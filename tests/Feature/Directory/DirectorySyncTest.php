<?php

namespace Tests\Feature\Directory;

use App\Directory\DirectorySync;
use App\Directory\DirectorySyncResult;
use App\Models\Company;
use App\Models\DirectoryEntry;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The nightly copy of each company's people from Microsoft Graph
 * (odd/tasks/personas.md WU1). Graph and the token endpoint are always
 * faked: never the real network.
 */
class DirectorySyncTest extends TestCase
{
    use RefreshDatabase;

    private const TENANT = '11111111-1111-1111-1111-111111111111';

    private const SHARED_TENANT = 'aaaaaaaa-0000-0000-0000-000000000001';

    private const SECRET = 'SECRET-VALUE-NEVER-SHOWN';

    /**
     * Pages of Graph users (or an HTTP status that fails the page) per tenant.
     *
     * @var array<string, list<list<array<string, mixed>>|int>>
     */
    private array $pages = [];

    /** @var array<string, string> Extra `@odata.nextLink` per tenant and page index ("tenant:page"). */
    private array $nextLinks = [];

    /** Answers the token endpoint instead of the default (a token per tenant). */
    private ?Closure $tokenEndpoint = null;

    /** Answers Graph instead of the default ($pages). */
    private ?Closure $graph = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 03:30', 'America/Bogota'));
        Http::preventStrayRequests();
        config([
            'services.microsoft.client_id' => 'client-id',
            'services.microsoft.client_secret' => self::SECRET,
        ]);

        // The first matching fake wins, so tests swap these closures instead.
        Http::fake([
            'login.microsoftonline.com/*' => fn (Request $request) => $this->tokenEndpoint === null
                ? Http::response([
                    'access_token' => 'token-'.explode('/', (string) parse_url($request->url(), PHP_URL_PATH))[1],
                    'expires_in' => 3600,
                ])
                : ($this->tokenEndpoint)($request),
            'graph.microsoft.com/*' => fn (Request $request) => $this->graph === null ? $this->graphPage($request) : ($this->graph)($request),
        ]);
    }

    public function test_it_copies_every_page_of_a_tenants_people_into_its_company(): void
    {
        $company = Company::factory()->forTenant(self::TENANT, ['segurex.com'])->create(['name' => 'Segurex Latam']);
        $ana = $this->graphUser([
            'displayName' => 'Ana María Pérez',
            'jobTitle' => 'Analista de cartera',
            'department' => 'Finanzas',
            'officeLocation' => 'Bogotá · Piso 3',
            'businessPhones' => ['+57 601 555 0101', '+57 601 555 0102'],
            'mail' => 'ana.perez@segurex.com',
        ]);
        $this->pages[self::TENANT] = [
            [$ana, $this->graphUser()],
            [$this->graphUser()],
        ];

        $result = $this->sync();

        $this->assertSame([], $result->failedTenants);
        $this->assertSame([$company->id => ['name' => 'Segurex Latam', 'saved' => 3, 'removed' => 0]], $result->companies);
        $this->assertSame(3, DirectoryEntry::query()->where('company_id', $company->id)->count());

        $entry = DirectoryEntry::query()->where('azure_oid', $ana['id'])->sole();
        $this->assertSame(self::TENANT, $entry->tenant_id);
        $this->assertSame('Ana María Pérez', $entry->display_name);
        $this->assertSame('Analista de cartera', $entry->job_title);
        $this->assertSame('Finanzas', $entry->department);
        $this->assertSame('Bogotá · Piso 3', $entry->office_location);
        $this->assertSame('+57 601 555 0101', $entry->business_phone);
        $this->assertSame('ana.perez@segurex.com', $entry->email);
        $this->assertNull($entry->hire_date);
        $this->assertTrue($entry->synced_at->equalTo(now()));
        $this->assertStringContainsString('ana maria perez analista de cartera finanzas', $entry->search_text);

        // One token, the first page with the fields it needs, then the next link as given.
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/users?')
            && str_contains(urldecode($request->url()), '$select=id,displayName,jobTitle,department,officeLocation,businessPhones,mail,userPrincipalName,employeeHireDate,accountEnabled,userType')
            && str_contains(urldecode($request->url()), '$top=999')
            && $request->header('Authorization') === ['Bearer token-'.self::TENANT]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '$skiptoken=1'));
    }

    public function test_a_next_link_to_another_host_is_refused_and_the_tenant_keeps_its_entries(): void
    {
        $company = Company::factory()->forTenant(self::TENANT)->create();
        $kept = DirectoryEntry::factory()->forCompany($company)->create(['synced_at' => now()->subDay()]);
        $this->pages[self::TENANT] = [[$this->graphUser()], [$this->graphUser()]];
        $this->nextLinks[self::TENANT.':0'] = 'https://graph.microsoft.com.evil.example/v1.0/users?$skiptoken=1';

        $result = $this->sync();

        $this->assertSame([self::TENANT], $result->failedTenants);
        $this->assertSame([$kept->id], DirectoryEntry::query()->pluck('id')->all());
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil'));
    }

    public function test_disabled_accounts_guests_and_people_without_an_email_are_left_out(): void
    {
        $company = Company::factory()->forTenant(self::TENANT, ['segurex.com'])->create();
        $upnOnly = $this->graphUser(['mail' => null, 'userPrincipalName' => 'luis.gomez@segurex.com']);
        $this->pages[self::TENANT] = [[
            $this->graphUser(['accountEnabled' => false]),
            $this->graphUser(['userType' => 'Guest']),
            $this->graphUser(['mail' => null, 'userPrincipalName' => 'socio_otra.com#EXT#@segurex.onmicrosoft.com']),
            $this->graphUser(['mail' => '', 'userPrincipalName' => null]),
            $upnOnly,
        ]];

        $this->sync();

        $entry = DirectoryEntry::query()->sole();
        $this->assertSame($upnOnly['id'], $entry->azure_oid);
        $this->assertSame('luis.gomez@segurex.com', $entry->email);
        $this->assertSame($company->id, $entry->company_id);
    }

    /**
     * Like the viewer side (`is_guest = null` is a member): only an
     * explicit «Guest» stays out.
     */
    public function test_people_without_a_user_type_count_as_members(): void
    {
        Company::factory()->forTenant(self::TENANT, ['segurex.com'])->create();
        $missing = $this->graphUser(['mail' => 'sin.tipo@segurex.com']);
        unset($missing['userType']);
        $this->pages[self::TENANT] = [[
            $this->graphUser(['mail' => 'nulo@segurex.com', 'userType' => null]),
            $missing,
            $this->graphUser(['mail' => 'invitado@segurex.com', 'userType' => 'Guest']),
        ]];

        $this->sync();

        $this->assertSame(['nulo@segurex.com', 'sin.tipo@segurex.com'], DirectoryEntry::query()->orderBy('email')->pluck('email')->all());
    }

    public function test_the_mobile_phone_is_never_stored(): void
    {
        Company::factory()->forTenant(self::TENANT)->create();
        $this->pages[self::TENANT] = [[$this->graphUser(['mobilePhone' => '+57 300 999 8877', 'businessPhones' => []])]];

        $this->sync();

        $this->assertFalse(Schema::hasColumn('directory_entries', 'mobile_phone'));
        $this->assertNull(DirectoryEntry::query()->sole()->business_phone);
        $this->assertStringNotContainsString('300 999 8877', json_encode(DirectoryEntry::query()->get()->toArray()));
    }

    public function test_people_of_a_shared_tenant_go_to_the_company_of_their_domain_or_the_primary(): void
    {
        $segurex = Company::factory()->forTenant(self::SHARED_TENANT, ['segurex.com'])->create();
        $derivados = Company::factory()->forTenant(self::SHARED_TENANT, ['derivados.com'], primary: false)->create();
        $this->pages[self::SHARED_TENANT] = [[
            $this->graphUser(['mail' => 'ana@segurex.com']),
            $this->graphUser(['mail' => 'luis@Derivados.com']),
            $this->graphUser(['mail' => 'sala@segurex.onmicrosoft.com']),
        ]];

        $result = $this->sync();

        $this->assertSame(
            ['ana@segurex.com' => $segurex->id, 'luis@Derivados.com' => $derivados->id, 'sala@segurex.onmicrosoft.com' => $segurex->id],
            DirectoryEntry::query()->orderBy('id')->pluck('company_id', 'email')->all(),
        );
        $this->assertSame(2, $result->companies[$segurex->id]['saved']);
        $this->assertSame(1, $result->companies[$derivados->id]['saved']);
        // One tenant, one copy.
        Http::assertSentCount(2);
    }

    public function test_inactive_companies_get_no_entries(): void
    {
        Company::factory()->inactive()->forTenant(self::TENANT)->create();
        $active = Company::factory()->forTenant(self::SHARED_TENANT, ['segurex.com'])->create();
        $inactive = Company::factory()->inactive()->forTenant(self::SHARED_TENANT, ['derivados.com'], primary: false)->create();
        $this->pages[self::SHARED_TENANT] = [[
            $this->graphUser(['mail' => 'ana@segurex.com']),
            $this->graphUser(['mail' => 'luis@derivados.com']),
        ]];

        $result = $this->sync();

        $this->assertSame(['ana@segurex.com'], DirectoryEntry::query()->pluck('email')->all());
        $this->assertSame([$active->id], array_keys($result->companies));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::TENANT));
        $this->assertNotNull($inactive->id);
    }

    public function test_entries_of_a_company_that_is_no_longer_active_are_removed(): void
    {
        $inactive = Company::factory()->inactive()->forTenant(self::TENANT)->create();
        DirectoryEntry::factory()->forCompany($inactive)->count(2)->create(['synced_at' => now()->subDay()]);

        $this->sync();

        $this->assertSame(0, DirectoryEntry::query()->count());
        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function failingStatuses(): array
    {
        return [
            'unauthorized twice' => [401],
            'no permission (no consent for User.Read.All)' => [403],
            'throttled' => [429],
            'outage' => [503],
        ];
    }

    #[DataProvider('failingStatuses')]
    public function test_a_failing_tenant_keeps_its_entries_and_the_others_are_still_copied(int $status): void
    {
        Log::spy();
        $failing = Company::factory()->forTenant(self::TENANT)->create();
        $working = Company::factory()->forTenant(self::SHARED_TENANT)->create();
        $kept = DirectoryEntry::factory()->forCompany($failing)->create(['synced_at' => now()->subDay()]);
        $this->pages[self::TENANT] = [[$this->graphUser()], $status];
        $this->pages[self::SHARED_TENANT] = [[$this->graphUser(), $this->graphUser()]];

        $result = $this->sync();

        $this->assertSame([self::TENANT], $result->failedTenants);
        $this->assertTrue(DirectoryEntry::query()->whereKey($kept->id)->exists());
        $this->assertSame(1, DirectoryEntry::query()->where('company_id', $failing->id)->count());
        $this->assertSame(2, DirectoryEntry::query()->where('company_id', $working->id)->count());
        $this->assertArrayNotHasKey($failing->id, $result->companies);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($status) {
            return $context['tenant_id'] === self::TENANT
                && $context['status'] === $status
                && ! str_contains($message.json_encode($context), self::SECRET)
                && ! str_contains($message.json_encode($context), 'token-');
        });
    }

    public function test_a_tenant_without_consent_keeps_its_entries(): void
    {
        $company = Company::factory()->forTenant(self::TENANT)->create();
        $kept = DirectoryEntry::factory()->forCompany($company)->create(['synced_at' => now()->subDay()]);
        $this->tokenEndpoint = fn () => Http::response([
            'error' => 'unauthorized_client',
            'error_codes' => [700016],
        ], 400);

        $result = $this->sync();

        $this->assertSame([self::TENANT], $result->failedTenants);
        $this->assertSame([$kept->id], DirectoryEntry::query()->pluck('id')->all());
    }

    public function test_entries_not_seen_in_the_copy_are_removed_and_seen_ones_updated(): void
    {
        $company = Company::factory()->forTenant(self::TENANT)->create();
        $gone = DirectoryEntry::factory()->forCompany($company)->create(['synced_at' => now()->subDay()]);
        $stays = DirectoryEntry::factory()->forCompany($company)->create(['synced_at' => now()->subDay(), 'job_title' => 'Auxiliar']);
        $this->pages[self::TENANT] = [[$this->graphUser(['id' => $stays->azure_oid, 'jobTitle' => 'Coordinadora', 'mail' => $stays->email])]];

        $result = $this->sync();

        $this->assertFalse(DirectoryEntry::query()->whereKey($gone->id)->exists());
        $this->assertSame('Coordinadora', $stays->fresh()->job_title);
        $this->assertSame(1, DirectoryEntry::query()->count());
        $this->assertSame(['name' => $company->name, 'saved' => 1, 'removed' => 1], $result->companies[$company->id]);
    }

    public function test_a_rejected_token_is_renewed_once_and_retried_once(): void
    {
        Company::factory()->forTenant(self::TENANT)->create();
        $this->pages[self::TENANT] = [[$this->graphUser()]];
        $tokens = ['stale', 'fresh'];
        $attempts = 0;
        $this->tokenEndpoint = function () use (&$tokens) {
            return Http::response(['access_token' => array_shift($tokens), 'expires_in' => 3600]);
        };
        $this->graph = function (Request $request) use (&$attempts) {
            $attempts++;

            return $request->header('Authorization') === ['Bearer fresh']
                ? Http::response(['value' => [$this->graphUser()]])
                : Http::response(['error' => ['code' => 'InvalidAuthenticationToken']], 401);
        };

        $result = $this->sync();

        $this->assertSame([], $result->failedTenants);
        $this->assertSame(2, $attempts);
        $this->assertSame([], $tokens);
        $this->assertSame(1, DirectoryEntry::query()->count());
    }

    /**
     * Entra keeps the hire date as a moment in UTC, usually midnight UTC
     * (the admin center) or midnight of some local time. The day is the UTC
     * day twelve hours after that moment, so neither lands on the day before.
     */
    public function test_the_hire_date_is_the_day_entra_wrote_whatever_the_midnight(): void
    {
        Company::factory()->forTenant(self::TENANT)->create();
        $this->pages[self::TENANT] = [[
            $this->graphUser(['mail' => 'a@x.com', 'employeeHireDate' => '2014-01-01T00:00:00Z']),
            $this->graphUser(['mail' => 'b@x.com', 'employeeHireDate' => '2021-06-01T05:00:00Z']),
            $this->graphUser(['mail' => 'c@x.com', 'employeeHireDate' => '2019-08-31T22:00:00-02:00']),
            $this->graphUser(['mail' => 'd@x.com', 'employeeHireDate' => '2022-02-27T22:00:00Z']),
            $this->graphUser(['mail' => 'e@x.com', 'employeeHireDate' => '2020-03-15T03:00:00Z']),
            $this->graphUser(['mail' => 'f@x.com', 'employeeHireDate' => 'no es una fecha']),
        ]];

        $this->sync();

        $dates = DirectoryEntry::query()->orderBy('email')->get()->map(fn (DirectoryEntry $entry) => $entry->hire_date?->toDateString())->all();
        // Midnight UTC, midnight in Bogotá, midnight UTC written with an offset, midnight in UTC+2, 3 a.m. UTC.
        $this->assertSame(['2014-01-01', '2021-06-01', '2019-09-01', '2022-02-28', '2020-03-15', null], $dates);
    }

    public function test_a_tenant_bigger_than_the_cap_fails_and_keeps_its_entries(): void
    {
        $company = Company::factory()->forTenant(self::TENANT)->create();
        $kept = DirectoryEntry::factory()->forCompany($company)->create(['synced_at' => now()->subDay()]);
        $this->pages[self::TENANT] = [[$this->graphUser(), $this->graphUser()], [$this->graphUser(), $this->graphUser()]];

        $result = $this->app->makeWith(DirectorySync::class, ['maxUsers' => 3])->run();

        $this->assertSame([self::TENANT], $result->failedTenants);
        $this->assertSame([$kept->id], DirectoryEntry::query()->pluck('id')->all());
    }

    private function sync(): DirectorySyncResult
    {
        return $this->app->make(DirectorySync::class)->run();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function graphUser(array $overrides = []): array
    {
        $name = fake()->name();

        return array_merge([
            'id' => (string) Str::uuid(),
            'displayName' => $name,
            'jobTitle' => fake()->jobTitle(),
            'department' => 'Operaciones',
            'officeLocation' => 'Bogotá',
            'businessPhones' => ['+57 601 555 '.fake()->numerify('####')],
            'mobilePhone' => '+57 300 '.fake()->numerify('### ####'),
            'mail' => Str::slug($name, '.').'.'.fake()->unique()->numerify('###').'@segurex.com',
            'userPrincipalName' => Str::slug($name, '.').'@segurex.onmicrosoft.com',
            'employeeHireDate' => null,
            'accountEnabled' => true,
            'userType' => 'Member',
        ], $overrides);
    }

    private function graphPage(Request $request): mixed
    {
        $tenant = Str::after((string) ($request->header('Authorization')[0] ?? ''), 'Bearer token-');
        $page = (int) Str::between(urldecode($request->url()).'&', '$skiptoken=', '&');
        $pages = $this->pages[$tenant] ?? [[]];
        $current = $pages[$page] ?? [];

        if (is_int($current)) {
            return Http::response(['error' => ['code' => 'Failure']], $current);
        }

        $body = ['value' => $current];
        $next = $this->nextLinks["{$tenant}:{$page}"] ?? (isset($pages[$page + 1]) ? 'https://graph.microsoft.com/v1.0/users?$select=id&$top=999&$skiptoken='.($page + 1) : null);

        if ($next !== null) {
            $body['@odata.nextLink'] = $next;
        }

        return Http::response($body);
    }
}
