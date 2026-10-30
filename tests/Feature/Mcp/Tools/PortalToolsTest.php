<?php

namespace Tests\Feature\Mcp\Tools;

use App\Models\Announcement;
use App\Models\DirectoryEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What every read tool shares: listed read only with a Spanish description
 * and its schema, and the two queries added for them next to the portal's
 * own (Announcement::scopeUnreadByPersonOf(),
 * DirectoryEntry::scopeWithShownBirthday()).
 */
class PortalToolsTest extends TestCase
{
    use ConnectsTwoAccounts, RefreshDatabase;

    private const TOOLS = [
        'quien_soy',
        'anuncios_pendientes',
        'anuncio',
        'proximas_fechas',
        'cumpleanos_y_aniversarios',
        'buscar_personas',
        'mis_apps',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connectTwoAccounts();
    }

    public function test_every_tool_is_listed_read_only_with_a_description_and_its_inputs(): void
    {
        $tools = collect($this->mcp($this->token, 'tools/list')->assertOk()->json('result.tools'))->keyBy('name');

        $this->assertSame(self::TOOLS, $tools->keys()->all());

        foreach ($tools as $name => $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint'] ?? false, "{$name} is not read only.");
            $this->assertNotSame('', trim((string) ($tool['description'] ?? '')), "{$name} has no description.");
        }

        $this->assertSame(['id'], $tools['anuncio']['inputSchema']['required']);
        $this->assertSame(['texto'], $tools['buscar_personas']['inputSchema']['required']);
        $this->assertEquals(['minimum' => 1, 'maximum' => 90, 'default' => 30], array_intersect_key(
            $tools['proximas_fechas']['inputSchema']['properties']['dias'],
            ['minimum' => 0, 'maximum' => 0, 'default' => 0],
        ));
        $this->assertEquals(['minimum' => 0, 'maximum' => 31, 'default' => 7], array_intersect_key(
            $tools['cumpleanos_y_aniversarios']['inputSchema']['properties']['dias'],
            ['minimum' => 0, 'maximum' => 0, 'default' => 0],
        ));
        $this->assertEquals(['minLength' => 2, 'maxLength' => 60], array_intersect_key(
            $tools['buscar_personas']['inputSchema']['properties']['texto'],
            ['minLength' => 0, 'maxLength' => 0],
        ));
    }

    public function test_unread_by_person_of_leaves_out_what_any_account_of_the_person_read(): void
    {
        $readByLinked = Announcement::factory()->mandatory()->create();
        $readByLinked->markReadBy($this->linked);
        $readByStranger = Announcement::factory()->mandatory()->create();
        $readByStranger->markReadBy(User::factory()->microsoft($this->segurex)->create());
        $unread = Announcement::factory()->mandatory()->create();

        $ids = fn (User $user) => Announcement::query()->unreadByPersonOf($user)->orderBy('id')->pluck('id')->all();

        $this->assertSame([$readByStranger->id, $unread->id], $ids($this->approver));
        $this->assertSame([$readByStranger->id, $unread->id], $ids($this->linked));

        // Not linked: only its own reads count.
        $this->unlinkTheLinkedAccount();
        $this->assertSame([$readByLinked->id, $readByStranger->id, $unread->id], $ids($this->approver->fresh()));
    }

    public function test_with_shown_birthday_reads_only_the_entry_s_own_account_when_it_chose_to_show_it(): void
    {
        $shown = User::factory()->microsoft($this->segurex)->birthday(5, 20)->create();
        $hidden = User::factory()->microsoft($this->segurex)->birthday(6, 21, shown: false)->create();
        // Same object ID, other company: not the entry's account.
        $elsewhere = User::factory()->microsoft($this->other)->birthday(7, 22)->create();

        $entries = [
            'shown' => DirectoryEntry::factory()->forCompany($this->segurex)->create(['azure_oid' => $shown->azure_oid]),
            'hidden' => DirectoryEntry::factory()->forCompany($this->segurex)->create(['azure_oid' => $hidden->azure_oid]),
            'elsewhere' => DirectoryEntry::factory()->forCompany($this->segurex)->create(['azure_oid' => $elsewhere->azure_oid]),
        ];

        $birthdays = DirectoryEntry::query()
            ->select('directory_entries.id')
            ->withShownBirthday()
            ->get()
            ->mapWithKeys(fn (DirectoryEntry $entry) => [$entry->id => [$entry->shown_birthday_month, $entry->shown_birthday_day]]);

        $this->assertSame([5, 20], array_map('intval', $birthdays[$entries['shown']->id]));
        $this->assertSame([null, null], $birthdays[$entries['hidden']->id]);
        $this->assertSame([null, null], $birthdays[$entries['elsewhere']->id]);
    }
}
