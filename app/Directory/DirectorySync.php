<?php

namespace App\Directory;

use App\Microsoft\AppOnlyToken;
use App\Microsoft\GraphAppException;
use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Tenancy\TenantDirectory;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies the people of every active company from Microsoft Graph into
 * `directory_entries` (odd/tasks/personas.md WU1), once per tenant with an
 * app-only token (application permission User.Read.All, admin consent in
 * each tenant). Run nightly by `portal:sync-directory`.
 *
 * - Only enabled members with a usable email: no guests (B2B), no disabled
 *   accounts, and the email is `mail`, else a `userPrincipalName` that is
 *   not a guest's (#EXT#).
 * - Each person lands in the company the sign-in gives them
 *   (TenantDirectory::companyAmong()); people of no active company are
 *   left out.
 * - The personal mobile phone is never asked for nor stored.
 * - A tenant is written only after all of its pages were read: then its
 *   people are saved and its entries not seen in this run removed. Any
 *   failure (token, 401 after one renewal, 403, 429, 5xx, a next link
 *   outside Graph, more than the cap) keeps that tenant's entries as they
 *   were, logs a warning without secrets and goes on with the next one.
 * - Entries of tenants without an active company are removed.
 *
 * @see https://learn.microsoft.com/en-us/graph/api/user-list
 * @see https://learn.microsoft.com/en-us/graph/paging
 */
final class DirectorySync
{
    public const USERS_URL = 'https://graph.microsoft.com/v1.0/users';

    public const SELECT = 'id,displayName,jobTitle,department,officeLocation,businessPhones,mail,userPrincipalName,employeeHireDate,accountEnabled,userType';

    /** The largest page Graph gives for users. */
    public const PAGE_SIZE = 999;

    /** More people than this in one tenant stops its copy: something is wrong. */
    public const MAX_USERS = 20000;

    /** Next links are followed only within Graph. */
    private const GRAPH_ORIGIN = 'https://graph.microsoft.com/';

    /** Upper bound on pages, so empty pages with a next link never loop forever. */
    private const MAX_PAGES = 1000;

    private const TIMEOUT = 30;

    private const UPSERT_CHUNK = 500;

    private const GUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    public function __construct(
        private readonly AppOnlyToken $tokens,
        private readonly Http $http,
        private readonly int $maxUsers = self::MAX_USERS,
    ) {}

    public function run(): DirectorySyncResult
    {
        // Seconds, like the column: entries older than this were not seen.
        $runAt = CarbonImmutable::now()->startOfSecond();

        $tenantIds = Company::active()
            ->distinct()
            ->pluck('tenant_id')
            ->map(fn (string $tenantId) => strtolower(trim($tenantId)))
            ->unique()
            ->sort()
            ->values()
            ->all();

        $companies = [];
        $failed = [];

        foreach ($tenantIds as $tenantId) {
            try {
                $users = $this->read($tenantId);
            } catch (GraphAppException $e) {
                Log::warning('Directory copy failed; the tenant keeps its previous entries.', [
                    'tenant_id' => $tenantId,
                    'status' => $e->status,
                    'aadsts' => $e->aadsts,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = $tenantId;

                continue;
            }

            $companies += $this->store($tenantId, Company::query()->where('tenant_id', $tenantId)->get(), $users, $runAt);
        }

        // A deactivated company's people leave the directory too.
        DirectoryEntry::query()->whereNotIn('tenant_id', $tenantIds)->delete();

        return new DirectorySyncResult($companies, $failed);
    }

    /**
     * Every user of the tenant, all pages, or an exception.
     *
     * @return list<array<string, mixed>>
     *
     * @throws GraphAppException
     */
    private function read(string $tenantId): array
    {
        $users = [];
        $url = self::USERS_URL;
        $query = ['$select' => self::SELECT, '$top' => self::PAGE_SIZE];
        $renewed = false;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $body = $this->page($tenantId, $url, $query, $renewed);

            foreach ($body['value'] as $user) {
                if (is_array($user)) {
                    $users[] = $user;
                }
            }

            if (count($users) > $this->maxUsers) {
                throw new GraphAppException(sprintf('The tenant has more than %d users; its copy stops.', $this->maxUsers));
            }

            $next = $body['@odata.nextLink'] ?? null;

            if ($next === null) {
                return $users;
            }

            if (! is_string($next) || ! str_starts_with($next, self::GRAPH_ORIGIN)) {
                throw new GraphAppException('Microsoft Graph sent a next page link outside '.self::GRAPH_ORIGIN.'.');
            }

            // The next link already carries every query parameter.
            $url = $next;
            $query = null;
        }

        throw new GraphAppException(sprintf('More than %d pages of users; the copy stops.', self::MAX_PAGES));
    }

    /**
     * One page; a 401 renews the token once per tenant and retries once.
     *
     * @param  array<string, string|int>|null  $query
     * @return array{value: list<mixed>, '@odata.nextLink'?: mixed}
     *
     * @throws GraphAppException
     */
    private function page(string $tenantId, string $url, ?array $query, bool &$renewed): array
    {
        $response = $this->get($tenantId, $url, $query);

        if ($response->status() === 401 && ! $renewed) {
            $renewed = true;
            $this->tokens->forget($tenantId);
            $response = $this->get($tenantId, $url, $query);
        }

        if (! $response->successful()) {
            throw new GraphAppException(sprintf('Microsoft Graph answered HTTP %d.', $response->status()), $response->status());
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['value'] ?? null)) {
            throw new GraphAppException('Microsoft Graph sent an unexpected answer.', $response->status());
        }

        return $body;
    }

    /**
     * @param  array<string, string|int>|null  $query  Null keeps the URL's own query (a next link).
     *
     * @throws GraphAppException
     */
    private function get(string $tenantId, string $url, ?array $query): Response
    {
        $request = $this->http->withToken($this->tokens->for($tenantId))->acceptJson()->timeout(self::TIMEOUT);

        try {
            return $query === null ? $request->get($url) : $request->get($url, $query);
        } catch (ConnectionException) {
            // No previous exception: its message may echo the request.
            throw new GraphAppException('Microsoft Graph could not be reached.');
        }
    }

    /**
     * Saves the tenant's people and removes its entries not seen in this
     * run, in one transaction.
     *
     * @param  Collection<int, Company>  $tenantCompanies  Every company of the tenant, active or not.
     * @param  list<array<string, mixed>>  $users
     * @return array<int, array{name: string, saved: int, removed: int}>
     */
    private function store(string $tenantId, Collection $tenantCompanies, array $users, CarbonInterface $runAt): array
    {
        $rows = [];

        foreach ($users as $user) {
            $row = $this->row($tenantId, $tenantCompanies, $user, $runAt);

            if ($row !== null) {
                $rows[$row['azure_oid']] = $row;
            }
        }

        $summary = [];

        foreach ($tenantCompanies->where('is_active', true) as $company) {
            $summary[$company->id] = ['name' => (string) $company->name, 'saved' => 0, 'removed' => 0];
        }

        foreach ($rows as $row) {
            $summary[$row['company_id']]['saved']++;
        }

        DB::transaction(function () use ($tenantId, $rows, $runAt, &$summary) {
            foreach (array_chunk(array_values($rows), self::UPSERT_CHUNK) as $chunk) {
                DirectoryEntry::query()->upsert($chunk, ['tenant_id', 'azure_oid'], [
                    'company_id', 'display_name', 'job_title', 'department', 'office_location',
                    'business_phone', 'email', 'hire_date', 'search_text', 'synced_at',
                ]);
            }

            $stale = DirectoryEntry::query()->where('tenant_id', $tenantId)->where('synced_at', '<', $runAt);

            $removed = (clone $stale)
                ->selectRaw('company_id, count(*) as removed')
                ->groupBy('company_id')
                ->pluck('removed', 'company_id');

            foreach ($removed as $companyId => $count) {
                if (isset($summary[$companyId])) {
                    $summary[$companyId]['removed'] += (int) $count;
                }
            }

            $stale->delete();
        });

        return $summary;
    }

    /**
     * The entry of one Graph user, or null when they stay out.
     *
     * @param  Collection<int, Company>  $tenantCompanies
     * @param  array<string, mixed>  $user
     * @return array<string, mixed>|null
     */
    private function row(string $tenantId, Collection $tenantCompanies, array $user, CarbonInterface $runAt): ?array
    {
        if (($user['accountEnabled'] ?? null) === false || ($user['userType'] ?? null) !== 'Member') {
            return null;
        }

        $oid = is_string($user['id'] ?? null) ? strtolower(trim($user['id'])) : '';
        $email = self::email($user);

        if (preg_match(self::GUID, $oid) !== 1 || $email === null) {
            return null;
        }

        $company = TenantDirectory::companyAmong($tenantCompanies, $email);

        if ($company === null) {
            return null;
        }

        $searched = [
            'display_name' => self::text($user['displayName'] ?? null) ?? $email,
            'job_title' => self::text($user['jobTitle'] ?? null),
            'department' => self::text($user['department'] ?? null),
            'office_location' => self::text($user['officeLocation'] ?? null),
            'email' => $email,
        ];

        return [
            'company_id' => $company->id,
            'tenant_id' => $tenantId,
            'azure_oid' => $oid,
            ...$searched,
            'business_phone' => self::phone($user['businessPhones'] ?? null),
            'hire_date' => self::hireDate($user['employeeHireDate'] ?? null),
            'search_text' => DirectoryEntry::searchTextFor($searched),
            'synced_at' => $runAt->toDateTimeString(),
        ];
    }

    /**
     * `mail`, else the sign-in name unless it is a guest's (#EXT#).
     *
     * @param  array<string, mixed>  $user
     */
    private static function email(array $user): ?string
    {
        foreach (['mail', 'userPrincipalName'] as $field) {
            $value = is_string($user[$field] ?? null) ? trim($user[$field]) : '';

            if ($value !== '' && str_contains($value, '@') && stripos($value, '#EXT#') === false && strlen($value) <= 320) {
                return $value;
            }
        }

        return null;
    }

    private static function text(mixed $value, int $length = 255): ?string
    {
        $text = is_string($value) ? Str::squish($value) : '';

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    /**
     * The first office phone; never the mobile.
     */
    private static function phone(mixed $phones): ?string
    {
        foreach (is_array($phones) ? $phones : [] as $phone) {
            if (($text = self::text($phone, 64)) !== null) {
                return $text;
            }
        }

        return null;
    }

    /**
     * The day of the hire in the app timezone (Bogotá): Entra keeps it as
     * a moment in UTC.
     */
    private static function hireDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'))->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
