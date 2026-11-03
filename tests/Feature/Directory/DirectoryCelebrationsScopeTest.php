<?php

namespace Tests\Feature\Directory;

use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DirectoryEntry::scopeCelebrationsOf(), shared by «Cumpleaños y
 * aniversarios» of the home screen and the Monday digest: one company's
 * directory, with the birthday of each entry's own account (same tenant,
 * object ID and company) only when it chose to show it.
 */
class DirectoryCelebrationsScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_one_company_with_the_birthdays_its_people_chose_to_show(): void
    {
        $segurex = Company::factory()->create(['short_name' => 'Segurex']);
        $derivados = Company::factory()->create(['short_name' => 'Derivados']);
        $this->entry($segurex, 'Luis Gómez', User::factory()->microsoft($segurex)->birthday(11, 11));
        $this->entry($segurex, 'Marta Ruiz', User::factory()->microsoft($segurex)->birthday(11, 12, shown: false));
        $this->entry($segurex, 'Sin cuenta', null);
        $this->entry($derivados, 'Jorge Ríos', User::factory()->microsoft($derivados)->birthday(11, 10));

        $rows = DirectoryEntry::query()
            ->select(['directory_entries.id', 'directory_entries.display_name'])
            ->celebrationsOf($segurex->id)
            ->orderBy('display_name')
            ->get()
            ->map(fn (DirectoryEntry $entry) => [$entry->display_name, $entry->getAttribute('shown_birthday_month'), $entry->getAttribute('shown_birthday_day')])
            ->all();

        $this->assertEquals([['Luis Gómez', 11, 11], ['Marta Ruiz', null, null], ['Sin cuenta', null, null]], $rows);
    }

    public function test_an_account_of_another_company_never_lends_its_birthday(): void
    {
        $segurex = Company::factory()->create();
        $derivados = Company::factory()->forTenant((string) $segurex->tenant_id, [], primary: false)->create();
        // Same tenant and object ID, but the account belongs to the other company.
        $user = User::factory()->microsoft($derivados)->birthday(11, 11)->create();
        DirectoryEntry::factory()->forCompany($segurex)->create(['tenant_id' => $user->tenant_id, 'azure_oid' => $user->azure_oid, 'display_name' => 'Luis Gómez']);

        $entry = DirectoryEntry::query()->select(['directory_entries.id'])->celebrationsOf($segurex->id)->sole();

        $this->assertNull($entry->getAttribute('shown_birthday_month'));
    }

    private function entry(Company $company, string $name, mixed $factory): DirectoryEntry
    {
        $user = $factory?->create(['name' => $name]);

        return DirectoryEntry::factory()->forCompany($company)->create(array_filter([
            'tenant_id' => $user?->tenant_id,
            'azure_oid' => $user?->azure_oid,
            'display_name' => $name,
        ]));
    }
}
