<?php

namespace Tests\Feature\Home;

use App\Models\Company;
use App\Models\DirectoryEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * «Cumpleaños y aniversarios» in «Mi día» (odd/tasks/personas.md WU2):
 * today and the next seven days (Bogotá), of the viewer's own company
 * directory. Birthdays only of accounts that chose to show them (/cuenta,
 * same tenant, object ID and company as the directory entry); work
 * anniversaries from the hire date Microsoft Entra holds, from one year.
 */
class HomeCelebrationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();

        // Friday 30 October 2026.
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', 'America/Bogota'));

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'short_name' => 'Segurex']);
        $this->ana = User::factory()->microsoft($this->segurex)->create(['name' => 'Ana María Pérez']);
    }

    public function test_an_opted_in_birthday_shows_with_its_day_and_a_teams_greeting(): void
    {
        $this->person('Luis Gómez', birthday: [11, 2], email: 'luis.gomez@segurex.com');

        $rows = $this->rows();

        $this->assertSame([['Luis Gómez', 'Lunes', 'Cumpleaños', '2026-11-02']], $this->summary($rows));
        $link = $this->greeting($rows[0]);
        $this->assertNotNull($link);
        $this->assertSame('_blank', $link->getAttribute('target'));
        $this->assertSame('noopener', $link->getAttribute('rel'));
        $this->assertSame(
            'https://teams.microsoft.com/l/chat/0/0?users=luis.gomez%40segurex.com&message=%C2%A1Feliz%20cumplea%C3%B1os%2C%20Luis%21',
            $link->getAttribute('href'),
        );
        $this->assertStringContainsString('Felicitar', $link->textContent);
        $this->assertStringContainsString('Luis Gómez', $link->textContent, 'The screen reader text names the person.');
    }

    public function test_today_and_tomorrow_say_so(): void
    {
        $this->person('Luis Gómez', birthday: [10, 30]);
        $this->person('Marta Ruiz', birthday: [10, 31]);

        $this->assertSame([
            ['Luis Gómez', 'Hoy', 'Cumpleaños', '2026-10-30'],
            ['Marta Ruiz', 'Mañana', 'Cumpleaños', '2026-10-31'],
        ], $this->summary($this->rows()));
    }

    public function test_a_birthday_not_chosen_to_show_stays_hidden(): void
    {
        $this->person('Luis Gómez', birthday: [10, 30], shown: false);

        // (The name is in the search index: only the block is checked.)
        $this->home()->assertDontSee('id="celebraciones"', false);
    }

    public function test_only_the_viewers_company_even_in_a_shared_tenant(): void
    {
        $derivados = Company::factory()->forTenant($this->segurex->tenant_id, ['derivados.com'], primary: false)->create(['short_name' => 'Derivados']);
        $this->person('Persona de Derivados', birthday: [10, 30], company: $derivados, hired: '2020-10-30');
        $this->person('Persona de otra empresa', birthday: [10, 30], company: Company::factory()->create(), hired: '2020-10-30');

        // An account of Derivados whose directory entry is in Segurex: the
        // birthday was chosen for Derivados, so Segurex does not see it.
        $moved = User::factory()->microsoft($derivados)->birthday(10, 30)->create();
        DirectoryEntry::factory()->forCompany($this->segurex)->create([
            'tenant_id' => $moved->tenant_id,
            'azure_oid' => $moved->azure_oid,
            'display_name' => 'Persona movida',
        ]);

        $this->person('Luis Gómez', birthday: [10, 30]);

        $this->assertSame([['Luis Gómez', 'Hoy', 'Cumpleaños', '2026-10-30']], $this->summary($this->rows()));
    }

    public function test_an_account_out_of_the_directory_shows_no_birthday(): void
    {
        // Disabled or removed in Entra: the nightly copy took them out.
        User::factory()->microsoft($this->segurex)->birthday(10, 30)->create(['name' => 'Sin directorio']);
        // Same object ID in another tenant: not the same person.
        $other = User::factory()->microsoft(Company::factory()->create())->birthday(10, 30)->create();
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['azure_oid' => $other->azure_oid, 'display_name' => 'Otro inquilino']);

        $this->home()->assertDontSee('id="celebraciones"', false);
    }

    public function test_29_february_is_celebrated_on_28_february_of_common_years(): void
    {
        $this->person('Luis Gómez', birthday: [2, 29], hired: '2020-02-29');

        $this->travelTo(CarbonImmutable::parse('2027-02-28 09:00', 'America/Bogota'));
        $this->assertSame([
            ['Luis Gómez', 'Hoy', 'Cumpleaños', '2027-02-28'],
            ['Luis Gómez', 'Hoy', '7 años en Segurex', '2027-02-28'],
        ], $this->summary($this->rows()));

        $this->travelTo(CarbonImmutable::parse('2028-02-28 09:00', 'America/Bogota'));
        $this->assertSame([
            ['Luis Gómez', 'Mañana', 'Cumpleaños', '2028-02-29'],
            ['Luis Gómez', 'Mañana', '8 años en Segurex', '2028-02-29'],
        ], $this->summary($this->rows()));
    }

    public function test_the_window_is_today_and_the_next_seven_days(): void
    {
        $this->person('Ayer', birthday: [10, 29]);
        $this->person('Día cero', birthday: [10, 30]);
        $this->person('Día siete', birthday: [11, 6]);
        $this->person('Día ocho', birthday: [11, 7]);

        $this->assertSame([
            ['Día cero', 'Hoy', 'Cumpleaños', '2026-10-30'],
            ['Día siete', 'Viernes', 'Cumpleaños', '2026-11-06'],
        ], $this->summary($this->rows()));
    }

    public function test_the_window_runs_into_the_next_year(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-12-28 09:00', 'America/Bogota'));
        $this->person('Enero tres', birthday: [1, 3]);
        $this->person('Enero cuatro', hired: '2020-01-04');
        $this->person('Enero cinco', birthday: [1, 5]);
        $this->person('Diciembre', birthday: [12, 28]);

        $this->assertSame([
            ['Diciembre', 'Hoy', 'Cumpleaños', '2026-12-28'],
            ['Enero tres', 'Domingo', 'Cumpleaños', '2027-01-03'],
            ['Enero cuatro', 'Lunes', '7 años en Segurex', '2027-01-04'],
        ], $this->summary($this->rows()));
    }

    public function test_anniversaries_need_a_full_year_and_count_the_years(): void
    {
        $this->person('Un año', hired: '2025-11-02');
        $this->person('Cinco años', hired: '2021-10-31');
        $this->person('Aún no', hired: '2026-11-01');
        $this->person('Recién llegado', hired: '2026-10-30');
        $this->person('Sin fecha', hired: null);

        $this->assertSame([
            ['Cinco años', 'Mañana', '5 años en Segurex', '2026-10-31'],
            ['Un año', 'Lunes', '1 año en Segurex', '2026-11-02'],
        ], $this->summary($rows = $this->rows()));

        $this->assertSame(
            'https://teams.microsoft.com/l/chat/0/0?users=cinco.anos%40segurex.com&message=%C2%A1Feliz%20aniversario%2C%20Cinco%21%20Gracias%20por%20estos%205%20a%C3%B1os%20en%20Segurex.',
            $this->greeting($rows[0])->getAttribute('href'),
        );
        $this->assertSame(
            'https://teams.microsoft.com/l/chat/0/0?users=un.ano%40segurex.com&message=%C2%A1Feliz%20aniversario%2C%20Un%21%20Gracias%20por%20este%20primer%20a%C3%B1o%20en%20Segurex.',
            $this->greeting($rows[1])->getAttribute('href'),
        );
    }

    public function test_an_anniversary_needs_no_portal_account(): void
    {
        DirectoryEntry::factory()->forCompany($this->segurex)->create(['display_name' => 'Sin cuenta', 'hire_date' => '2016-10-30']);

        $this->assertSame([['Sin cuenta', 'Hoy', '10 años en Segurex', '2026-10-30']], $this->summary($this->rows()));
    }

    public function test_the_viewers_own_row_has_no_greeting(): void
    {
        $this->ana->forceFill(['birthday_month' => 10, 'birthday_day' => 30, 'show_birthday' => true])->save();
        DirectoryEntry::factory()->forCompany($this->segurex)->create([
            'tenant_id' => $this->ana->tenant_id,
            'azure_oid' => $this->ana->azure_oid,
            'display_name' => 'Ana María Pérez',
            'hire_date' => '2024-10-30',
        ]);
        $this->person('Luis Gómez', birthday: [10, 30]);

        $rows = $this->rows();
        $this->assertSame([
            ['Ana María Pérez', 'Hoy', 'Cumpleaños', '2026-10-30'],
            ['Ana María Pérez', 'Hoy', '2 años en Segurex', '2026-10-30'],
            ['Luis Gómez', 'Hoy', 'Cumpleaños', '2026-10-30'],
        ], $this->summary($rows));
        $this->assertNull($this->greeting($rows[0]));
        $this->assertNull($this->greeting($rows[1]));
        $this->assertNotNull($this->greeting($rows[2]));
    }

    public function test_the_email_and_the_greeting_are_encoded(): void
    {
        $this->person('José Ñúñez', birthday: [10, 30], email: "jose+cumple&x'y@segurex.com");

        $href = $this->greeting($this->rows()[0])->getAttribute('href');

        $this->assertSame(
            'https://teams.microsoft.com/l/chat/0/0?users=jose%2Bcumple%26x%27y%40segurex.com&message=%C2%A1Feliz%20cumplea%C3%B1os%2C%20Jos%C3%A9%21',
            $href,
        );
    }

    public function test_by_date_then_name_at_most_eight_then_the_directory(): void
    {
        foreach (['Zoe', 'Bruno', 'Ángela', 'Carla', 'Diego', 'Elena', 'Fabio', 'Gabriel'] as $name) {
            $this->person($name, birthday: [11, 1]);
        }
        $this->person('Hoy primero', birthday: [10, 30]);

        $response = $this->home();
        $rows = $this->rows($response);

        $this->assertSame(
            ['Hoy primero', 'Ángela', 'Bruno', 'Carla', 'Diego', 'Elena', 'Fabio', 'Gabriel'],
            array_column($this->summary($rows), 0),
        );
        $more = $this->xpath($response)->query('//section[@id="celebraciones"]//a[@href="'.route('people.index').'"]');
        $this->assertSame(1, $more->length);
        $this->assertStringContainsString('Ver directorio', $more->item(0)->textContent);
    }

    public function test_eight_rows_need_no_link_to_the_directory(): void
    {
        foreach (range(1, 8) as $i) {
            $this->person("Persona {$i}", birthday: [10, 31]);
        }

        $response = $this->home();

        $this->assertCount(8, $this->rows($response));
        $this->assertSame(0, $this->xpath($response)->query('//section[@id="celebraciones"]//a[@href="'.route('people.index').'"]')->length);
    }

    public function test_nothing_in_the_window_leaves_the_block_out(): void
    {
        $this->person('Luis Gómez', birthday: [12, 24], hired: '2020-01-15');

        $this->home()->assertDontSee('id="celebraciones"', false)->assertDontSeeText('Cumpleaños y aniversarios');
    }

    public function test_rows_carry_their_date_for_the_saved_copy(): void
    {
        // resources/js/home.js hides a past row and fixes «Hoy» and «Mañana» in a copy replayed later.
        $this->person('Luis Gómez', birthday: [10, 30]);
        $this->person('Marta Ruiz', birthday: [11, 2]);

        $rows = $this->rows();
        $xpath = new DOMXPath($rows[0]->ownerDocument);

        $this->assertSame('2026-10-30', $rows[0]->getAttribute('data-celebration-date'));
        $today = $xpath->query('.//*[@data-celebration-day]', $rows[0])->item(0);
        $this->assertSame('Hoy', trim($today->textContent));
        $this->assertSame('Viernes', $today->getAttribute('data-celebration-day'));

        $later = $xpath->query('.//*[@data-celebration-day]', $rows[1])->item(0);
        $this->assertSame('Lunes', trim($later->textContent));
        $this->assertSame('Lunes', $later->getAttribute('data-celebration-day'));
        $this->assertSame('2026-11-02', $xpath->query('.//time', $rows[1])->item(0)->getAttribute('datetime'));
    }

    public function test_accounts_without_a_directory_see_no_block(): void
    {
        $this->person('Luis Gómez', birthday: [10, 30], hired: '2020-10-30');

        $guest = User::factory()->microsoft($this->segurex)->create(['is_guest' => true]);
        $this->actingAs($guest)->get('/')->assertOk()->assertDontSee('id="celebraciones"', false);

        $emergency = User::factory()->emergency()->create();
        $this->actingAs($emergency)->get('/')->assertOk()->assertDontSee('id="celebraciones"', false);
    }

    public function test_the_server_search_leaves_the_block_out(): void
    {
        // Like the directory of the search index: no directory query on ?q=.
        $this->person('Luis Gómez', birthday: [10, 30]);

        $this->home('?q=cartera')->assertDontSee('id="celebraciones"', false);
    }

    public function test_the_search_index_carries_no_birthday_or_hire_date(): void
    {
        $this->person('Luis Gómez', birthday: [10, 30], hired: '2020-10-30');

        $json = $this->xpath($this->home())->query('//script[@type="application/json"]')->item(0)->textContent;
        $people = json_decode($json, true, flags: JSON_THROW_ON_ERROR)['people'];

        $this->assertSame(['title', 'detail', 'search', 'href'], array_keys($people[0]));
        $this->assertStringNotContainsString('2020', $json);
        $this->assertStringNotContainsString('birthday', $json);
    }

    /**
     * A person of the directory with an account: same tenant, object ID and
     * company. $birthday is [month, day]; $company defaults to Segurex.
     *
     * @param  array{0: int, 1: int}|null  $birthday
     */
    private function person(string $name, ?array $birthday = null, bool $shown = true, ?string $hired = null, ?Company $company = null, ?string $email = null): DirectoryEntry
    {
        $company ??= $this->segurex;
        $factory = User::factory()->microsoft($company);
        if ($birthday !== null) {
            $factory = $factory->birthday($birthday[0], $birthday[1], $shown);
        }
        $user = $factory->create(['name' => $name]);

        return DirectoryEntry::factory()->forCompany($company)->create([
            'tenant_id' => $user->tenant_id,
            'azure_oid' => $user->azure_oid,
            'display_name' => $name,
            'email' => $email ?? Str::slug($name, '.').'@segurex.com',
            'hire_date' => $hired,
        ]);
    }

    private function home(string $query = ''): TestResponse
    {
        return $this->actingAs($this->ana->fresh())->get('/'.$query)->assertOk();
    }

    /**
     * @return list<DOMElement>
     */
    private function rows(?TestResponse $response = null): array
    {
        $xpath = $this->xpath($response ?? $this->home());
        $section = $xpath->query('//aside[@id="mi-dia"]//section[@id="celebraciones"]');
        $this->assertSame(1, $section->length, 'Expected the «Cumpleaños y aniversarios» block in «Mi día».');
        $this->assertStringContainsString('Cumpleaños y aniversarios', $section->item(0)->textContent);

        return iterator_to_array($xpath->query('.//li[@data-celebration-date]', $section->item(0)), false);
    }

    /**
     * [name, day label, «Cumpleaños» or the anniversary, date] of each row.
     *
     * @param  list<DOMElement>  $rows
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private function summary(array $rows): array
    {
        return array_map(function (DOMElement $row) {
            $xpath = new DOMXPath($row->ownerDocument);

            return [
                trim($xpath->query('.//*[@data-celebration-name]', $row)->item(0)->textContent),
                trim($xpath->query('.//*[@data-celebration-day]', $row)->item(0)->textContent),
                trim($xpath->query('.//*[@data-celebration-kind]', $row)->item(0)->textContent),
                $row->getAttribute('data-celebration-date'),
            ];
        }, $rows);
    }

    private function greeting(DOMElement $row): ?DOMElement
    {
        $link = (new DOMXPath($row->ownerDocument))->query('.//a[starts-with(@href, "https://teams.microsoft.com/")]', $row);

        return $link->length === 0 ? null : $link->item(0);
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8"?>'.$response->getContent());

        return new DOMXPath($document);
    }
}
