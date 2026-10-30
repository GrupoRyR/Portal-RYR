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
 *   failure (token, 401 after one renewal, 403, 429, 5xx, a redirect, a
 *   next link outside Graph, more than the cap, nobody to keep while the
 *   tenant has people, any other error) keeps that tenant's entries as
 *   they were, logs a warning without secrets and goes on with the next
 *   one.
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
        // Seconds, like the column.
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
                $tenantCompanies = Company::query()->where('tenant_id', $tenantId)->get();
                $rows = $this->rows($tenantId, $tenantCompanies, $this->read($tenantId), $runAt);

                // An empty answer is far likelier a fault than everyone gone.
                if ($rows === [] && DirectoryEntry::query()->where('tenant_id', $tenantId)->exists()) {
                    throw new GraphAppException('Microsoft Graph listed nobody to keep while the tenant has people in the directory.');
                }

                $companies += $this->store($tenantId, $tenantCompanies, $rows);
            } catch (Throwable $e) {
                Log::warning('Directory copy failed; the tenant keeps its previous entries.', self::failure($tenantId, $e));
                $failed[] = $tenantId;
            }
        }

        // A deactivated company's people leave the directory too.
        DirectoryEntry::query()->whereNotIn('tenant_id', $tenantIds)->delete();

        return new DirectorySyncResult($companies, $failed);
    }

    /**
     * What the log may say about a failed tenant: our own messages and
     * codes, and only the class of anything else (its message may echo a
     * request or a secret).
     *
     * @return array<string, mixed>
     */
    private static function failure(string $tenantId, Throwable $e): array
    {
        if ($e instanceof GraphAppException) {
            return ['tenant_id' => $tenantId, 'exception' => $e::class, 'status' => $e->status, 'aadsts' => $e->aadsts, 'error' => $e->getMessage()];
        }

        return ['tenant_id' => $tenantId, 'exception' => $e::class];
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

        // A 3xx (redirect not followed) fails here too.
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
        // A redirect is not followed: it would carry the token off Graph.
        $request = $this->http->withToken($this->tokens->for($tenantId))->acceptJson()->timeout(self::TIMEOUT)->withoutRedirecting();

        try {
            return $query === null ? $request->get($url) : $request->get($url, $query);
        } catch (ConnectionException) {
            // No previous exception: its message may echo the request.
            throw new GraphAppException('Microsoft Graph could not be reached.');
        }
    }

    /**
     * The entries of the tenant's users, one per object id.
     *
     * @param  Collection<int, Company>  $tenantCompanies  Every company of the tenant, active or not.
     * @param  list<array<string, mixed>>  $users
     * @return array<string, array<string, mixed>>
     */
    private function rows(string $tenantId, Collection $tenantCompanies, array $users, CarbonInterface $runAt): array
    {
        $rows = [];

        foreach ($users as $user) {
            $row = $this->row($tenantId, $tenantCompanies, $user, $runAt);

            if ($row !== null) {
                $rows[$row['azure_oid']] = $row;
            }
        }

        return $rows;
    }

    /**
     * Saves the tenant's people and removes its entries not seen in this
     * run, in one transaction.
     *
     * @param  Collection<int, Company>  $tenantCompanies  Every company of the tenant, active or not.
     * @param  array<string, array<string, mixed>>  $rows
     * @return array<int, array{name: string, saved: int, removed: int}>
     */
    private function store(string $tenantId, Collection $tenantCompanies, array $rows): array
    {
        $summary = [];

        foreach ($tenantCompanies->where('is_active', true) as $company) {
            $summary[$company->id] = ['name' => (string) $company->name, 'saved' => 0, 'removed' => 0];
        }

        foreach ($rows as $row) {
            $summary[$row['company_id']]['saved']++;
        }

        DB::transaction(function () use ($tenantId, $rows, &$summary) {
            foreach (array_chunk(array_values($rows), self::UPSERT_CHUNK) as $chunk) {
                DirectoryEntry::query()->upsert($chunk, ['tenant_id', 'azure_oid'], [
                    'company_id', 'display_name', 'job_title', 'department', 'office_location',
                    'business_phone', 'email', 'hire_date', 'search_text', 'synced_at',
                ]);
            }

            // Removed: what this run did not see, whatever its stamp, so a
            // run that overlaps another never takes people it did see.
            $stale = DirectoryEntry::query()
                ->where('tenant_id', $tenantId)
                ->toBase()
                ->get(['id', 'azure_oid', 'company_id'])
                ->reject(fn (object $entry) => isset($rows[$entry->azure_oid]));

            foreach ($stale->countBy('company_id') as $companyId => $count) {
                if (isset($summary[$companyId])) {
                    $summary[$companyId]['removed'] += $count;
                }
            }

            foreach ($stale->pluck('id')->chunk(self::UPSERT_CHUNK) as $ids) {
                DirectoryEntry::query()->whereKey($ids->values()->all())->delete();
            }
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
        // Only an explicit guest stays out: no user type counts as a member,
        // like `is_guest = null` on the viewer side.
        $guest = is_string($user['userType'] ?? null) && strcasecmp(trim($user['userType']), 'Guest') === 0;

        if (($user['accountEnabled'] ?? null) === false || $guest) {
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
     * `mail`, else the sign-in name unless it is a guest's (#EXT#); only a
     * valid address (anything appended, like `?cc=`, makes it invalid).
     *
     * @param  array<string, mixed>  $user
     */
    private static function email(array $user): ?string
    {
        foreach (['mail', 'userPrincipalName'] as $field) {
            $value = is_string($user[$field] ?? null) ? trim($user[$field]) : '';

            if ($value !== '' && stripos($value, '#EXT#') === false && strlen($value) <= 320
                && filter_var($value, FILTER_VALIDATE_EMAIL, FILTER_FLAG_EMAIL_UNICODE) !== false) {
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
     * The day of the hire. Entra keeps a moment in UTC that stands for a
     * day: midnight UTC when set in the admin center (in Bogotá, 19:00 of
     * the day before), or midnight of some local time. The UTC day twelve
     * hours later is that day for any midnight between UTC-12 and UTC+12.
     */
    private static function hireDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc()->addHours(12)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
