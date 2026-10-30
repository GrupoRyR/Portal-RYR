<?php

namespace App\Mcp\Tools;

use App\Catalog\AppCatalog;
use App\Mcp\SeenByAccounts;
use App\Models\App;
use App\View\Home\AppCardStatus;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `mis_apps`: the apps each account can open, as its home screen lists
 * them (App\Catalog\AppCatalog::visibleApps(): not hidden, for its company
 * and granted by an access rule), each once with the companies of the
 * accounts that can open it: the approving account's in catalog order,
 * then the others'. The link is the portal's launcher (/apps/{slug}),
 * which checks access again with the account the browser is signed in
 * with: the note names the accounts that can open it. At most LIMIT.
 */
#[Name('mis_apps')]
#[Description('Las aplicaciones del Portal RYR que pueden abrir las cuentas de esta conexión, cada una una sola vez: nombre, descripción, categoría, enlace para abrirla desde el portal, si está en mantenimiento, empresas y una nota con la cuenta con la que se debe abrir (el enlace usa la cuenta con la que el navegador tenga la sesión del portal). En el orden del catálogo, máximo 100.')]
#[IsReadOnly]
class MisApps extends PortalTool
{
    public const LIMIT = 100;

    public function __construct(private readonly AppCatalog $catalog) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        /** @var SeenByAccounts<App> $seen */
        $seen = new SeenByAccounts;
        /** @var array<int, list<string>> $openWith «Segurex (jhon.castro@segurex.com)» per app. */
        $openWith = [];

        foreach ($accounts as $account) {
            foreach ($this->catalog->visibleApps($account) as $app) {
                $seen->add($app->id, self::company($account), $app);
                $openWith[$app->id][] = self::company($account)." ({$account->email})";
            }
        }

        return Response::structured([
            'apps' => array_map(fn (array $entry) => [
                'nombre' => (string) $entry['item']->name,
                'descripcion' => (string) $entry['item']->description,
                'categoria' => self::blankToNull($entry['item']->category?->name),
                'url' => route('apps.open', $entry['item']),
                // The «En mantenimiento» chip of the home card (App\View\Home\AppCardStatus).
                'en_mantenimiento' => AppCardStatus::of($entry['item']) === AppCardStatus::Maintenance,
                'empresas' => $entry['companies'],
                'nota' => self::note($openWith[$entry['item']->id]),
            ], array_slice($seen->all(), 0, self::LIMIT)),
            'limite' => self::LIMIT,
        ]);
    }

    /**
     * /apps/{slug} opens with whichever account the browser is signed in
     * to the portal with, which may not be one that can open the app.
     *
     * @param  non-empty-list<string>  $accounts  «Segurex (jhon.castro@segurex.com)».
     */
    private static function note(array $accounts): string
    {
        return 'El enlace abre la app con la cuenta del portal que tenga la sesión en el navegador. Para esta app, usa tu cuenta de '
            .implode(' o de ', array_values(array_unique($accounts))).'.';
    }

    private static function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }

    /**
     * No input.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
