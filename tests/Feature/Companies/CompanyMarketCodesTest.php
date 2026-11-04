<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `companies.market_codes` (odd/tasks/mercados-o5.md M3): the input series
 * a company sees in its markets band, as data, never as rules by slug.
 */
class CompanyMarketCodesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_column_is_optional_and_existing_companies_have_none(): void
    {
        $this->assertTrue(Schema::hasColumn('companies', 'market_codes'));

        $company = Company::factory()->create();

        $this->assertNull($company->fresh()->market_codes);
        $this->assertSame([], $company->fresh()->marketCodes());
    }

    public function test_only_known_input_codes_are_stored_in_catalogue_order(): void
    {
        $company = Company::factory()->create(['market_codes' => ['bottles', 'trm', 'zinc', 'nope', 'zinc', 7]]);

        $this->assertSame(['zinc', 'bottles'], $company->fresh()->market_codes);
        $this->assertSame('["zinc","bottles"]', DB::table('companies')->where('id', $company->id)->value('market_codes'));
    }

    public function test_an_empty_selection_is_stored_as_none(): void
    {
        $company = Company::factory()->withMarketCodes(['zinc'])->create();

        $company->update(['market_codes' => ['trm', 'nope']]);
        $this->assertNull($company->fresh()->market_codes);

        $company->update(['market_codes' => []]);
        $this->assertNull(DB::table('companies')->where('id', $company->id)->value('market_codes'));
    }

    public function test_a_stored_code_that_left_the_catalogue_is_not_read(): void
    {
        $company = Company::factory()->create();
        DB::table('companies')->where('id', $company->id)->update(['market_codes' => '["zinc","gold","trm"]']);

        $this->assertSame(['zinc'], $company->fresh()->marketCodes());
    }

    public function test_a_change_of_the_selection_is_audited(): void
    {
        $company = Company::factory()->create();

        $company->update(['market_codes' => ['copper']]);

        $entry = DB::table('activity_log')->where('log_name', Company::AUDIT_LOG)->where('event', 'updated')->latest('id')->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('copper', (string) $entry->properties);
    }
}
