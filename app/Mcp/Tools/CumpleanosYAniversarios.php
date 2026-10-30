<?php

namespace App\Mcp\Tools;

use App\Catalog\AppSearch;
use App\Mcp\SeenByAccounts;
use App\Models\DirectoryEntry;
use App\Models\User;
use App\View\Home\Celebration;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `cumpleanos_y_aniversarios`: «Cumpleaños y aniversarios» of «Mi día» for
 * each account's company directory (DirectoryEntry::companyIdFor(): none
 * for guests, emergency accounts or an inactive company), over a window of
 * up to MAX_DAYS days:
 *
 * - birthdays only of accounts that chose to show them
 *   (DirectoryEntry::scopeWithShownBirthday()), day and month only: the
 *   portal keeps no birth year;
 * - work anniversaries, with the day and the years completed, as «Mi día»
 *   shows them; the hire date itself is never returned.
 *
 * The directory is read as the home screen reads it
 * (DirectoryEntry::homeDirectory(): by name, at most
 * HomeSearchIndex::PEOPLE_LIMIT), and the rows come from
 * App\View\Home\Celebration::within() with the longer window, so the tool
 * and «Mi día» agree.
 *
 * One row per directory entry: two people with one name stay two rows, and
 * namesakes in two companies stay one row per company. Only the
 * connection's own accounts are known to be one person (confirmed with
 * Microsoft when connecting and still linked, App\Mcp\ConnectionAccounts),
 * so their own birthday in both directories is one row with both
 * companies; nobody else's person is read. By date, then name, at most
 * LIMIT. No email, phone or «Felicitar» link. One query per account.
 */
#[Name('cumpleanos_y_aniversarios')]
#[Description('Cumpleaños y aniversarios de trabajo de las personas de las empresas de esta conexión, desde hoy hasta `dias` días adelante (de 0 a 31, 7 si no se indica; 0 es solo hoy), por fecha, máximo 30. Los cumpleaños son solo de quienes eligieron mostrarlo en el portal, con día y mes (nunca el año de nacimiento). Los aniversarios dicen los años cumplidos en la empresa.')]
#[IsReadOnly]
class CumpleanosYAniversarios extends PortalTool
{
    public const LIMIT = 30;

    public const DEFAULT_DAYS = Celebration::DAYS;

    public const MAX_DAYS = 31;

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        $days = (int) ($request->validate(
            ['dias' => ['sometimes', 'integer', 'between:0,'.self::MAX_DAYS]],
            self::messages(),
        )['dias'] ?? self::DEFAULT_DAYS);

        $today = self::today();
        // The connection's own accounts, by tenant and object ID: one person.
        $own = $accounts
            ->map(fn (User $account) => self::accountKey($account->tenant_id, $account->azure_oid))
            ->all();

        /** @var SeenByAccounts<Celebration> $seen */
        $seen = new SeenByAccounts;

        foreach ($accounts as $account) {
            $companyId = DirectoryEntry::companyIdFor($account);

            if ($companyId === null) {
                continue;
            }

            $people = DirectoryEntry::homeDirectory($companyId)->keyBy('id');
            $company = self::company($account);

            foreach (Celebration::within($people, $account, $today, $company, $days) as $row) {
                $seen->add(self::identity($row, $people[$row->entryId], $own), $company, $row);
            }
        }

        $entries = $seen->all();
        usort($entries, fn (array $a, array $b) => self::sortKey($a['item']) <=> self::sortKey($b['item']));

        return Response::structured([
            'celebraciones' => array_map(fn (array $entry) => [
                'nombre' => $entry['item']->name,
                'tipo' => $entry['item']->kind === Celebration::BIRTHDAY ? 'Cumpleaños' : 'Aniversario',
                'detalle' => $entry['item']->label,
                'anos_cumplidos' => self::years($entry['item']),
                'fecha' => self::iso($entry['item']->date),
                // Day and month: a birthday never shows a year.
                'fecha_texto' => Str::ucfirst($entry['item']->longDate()),
                'empresas' => $entry['companies'],
            ], array_slice($entries, 0, self::LIMIT)),
            'limite' => self::LIMIT,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'dias' => $schema->integer()
                ->min(0)
                ->max(self::MAX_DAYS)
                ->default(self::DEFAULT_DAYS)
                ->description('Cuántos días hacia adelante mirar, desde hoy (de 0 a 31; 0 es solo hoy).'),
        ];
    }

    /**
     * Which rows are the same: one per directory entry, except the
     * connection's own person, whose rows of the same kind, day and label
     * (their own birthday in both directories) are one.
     *
     * @param  list<string>  $own  {@see accountKey()} of the connection's accounts.
     */
    private static function identity(Celebration $row, DirectoryEntry $entry, array $own): string
    {
        $who = in_array(self::accountKey($entry->tenant_id, $entry->azure_oid), $own, true)
            ? 'connection'
            : 'entry:'.$row->entryId;

        return implode('|', [$who, $row->kind, $row->date->toDateString(), $row->label]);
    }

    private static function accountKey(?string $tenantId, ?string $azureOid): string
    {
        return strtolower((string) $tenantId).'|'.strtolower((string) $azureOid);
    }

    /**
     * Years completed of an anniversary («5 años en Segurex»); null for a birthday.
     */
    private static function years(Celebration $row): ?int
    {
        return $row->kind === Celebration::ANNIVERSARY && preg_match('/^(\d+) años? /u', $row->label, $match) === 1
            ? (int) $match[1]
            : null;
    }

    /**
     * Celebration::within()'s order: date, then name, a birthday before an anniversary.
     *
     * @return array{0: int, 1: string, 2: bool, 3: string}
     */
    private static function sortKey(Celebration $row): array
    {
        return [$row->date->getTimestamp(), AppSearch::normalize($row->name), $row->kind !== Celebration::BIRTHDAY, $row->name];
    }
}
