<?php

namespace App\Mcp\Tools;

use App\Catalog\AppSearch;
use App\Mcp\SeenByAccounts;
use App\Models\DirectoryEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `buscar_personas`: the /personas search (App\Http\Controllers\People\PeopleController)
 * over each account's company directory, with the same gate
 * (DirectoryEntry::companyIdFor(): none for guests, emergency accounts or
 * an inactive company) and the same matching (DirectoryEntry::scopeMatching(),
 * every word, ignoring accents and case). Only what /personas shows: name,
 * title, area, office, email and office phone. Never a birthday, a hire
 * date or a mobile phone (the directory holds none). By name, at most
 * LIMIT. One query per account with a directory.
 */
#[Name('buscar_personas')]
#[Description('Busca personas en el directorio de las empresas de esta conexión (el mismo de /personas en el portal), por nombre, cargo, área, oficina o correo; sin importar tildes ni mayúsculas, y deben estar todas las palabras. Devuelve nombre, cargo, área, oficina, correo, teléfono de oficina y empresa, por nombre, máximo 20.')]
#[IsReadOnly]
class BuscarPersonas extends PortalTool
{
    public const LIMIT = 20;

    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 60;

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        $text = trim((string) $request->validate(
            ['texto' => ['required', 'string', 'min:'.self::MIN_LENGTH, 'max:'.self::MAX_LENGTH]],
            self::messages(),
        )['texto']);
        $search = new AppSearch($text);

        // Only spaces or a single letter would match the whole directory.
        if (mb_strlen($text) < self::MIN_LENGTH || ! $search->isActive()) {
            return Response::error('El campo texto debe tener al menos '.self::MIN_LENGTH.' caracteres.');
        }

        /** @var SeenByAccounts<DirectoryEntry> $seen */
        $seen = new SeenByAccounts;

        foreach ($accounts as $account) {
            $companyId = DirectoryEntry::companyIdFor($account);

            if ($companyId === null) {
                continue;
            }

            $people = DirectoryEntry::query()
                ->select(['id', 'display_name', 'job_title', 'department', 'office_location', 'business_phone', 'email'])
                ->where('company_id', $companyId)
                ->matching($search)
                ->orderBy('display_name')
                ->orderBy('id')
                ->limit(self::LIMIT)
                ->get();

            foreach ($people as $person) {
                $seen->add($person->id, self::company($account), $person);
            }
        }

        $entries = $seen->all();
        usort($entries, fn (array $a, array $b) => [AppSearch::normalize($a['item']->display_name), $a['item']->id]
            <=> [AppSearch::normalize($b['item']->display_name), $b['item']->id]);

        return Response::structured([
            'personas' => array_map(fn (array $entry) => [
                'nombre' => (string) $entry['item']->display_name,
                'cargo' => self::blankToNull($entry['item']->job_title),
                'area' => self::blankToNull($entry['item']->department),
                'oficina' => self::blankToNull($entry['item']->office_location),
                'correo' => (string) $entry['item']->email,
                'telefono_oficina' => self::blankToNull($entry['item']->business_phone),
                // An entry belongs to one company's directory.
                'empresa' => $entry['companies'][0],
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
            'texto' => $schema->string()
                ->min(self::MIN_LENGTH)
                ->max(self::MAX_LENGTH)
                ->description('Qué buscar: nombre, cargo, área, oficina o correo (de 2 a 60 caracteres).')
                ->required(),
        ];
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
