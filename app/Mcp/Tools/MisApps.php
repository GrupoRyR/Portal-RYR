<?php

namespace App\Mcp\Tools;

use App\Catalog\AppCatalog;
use App\Mcp\SeenByAccounts;
use App\Models\App;
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
 * which checks access again. At most LIMIT.
 */
#[Name('mis_apps')]
#[Description('Las aplicaciones del Portal RYR que pueden abrir las cuentas de esta conexión, cada una una sola vez: nombre, descripción, categoría, enlace para abrirla desde el portal y empresas. En el orden del catálogo, máximo 100.')]
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

        foreach ($accounts as $account) {
            foreach ($this->catalog->visibleApps($account) as $app) {
                $seen->add($app->id, self::company($account), $app);
            }
        }

        return Response::structured([
            'apps' => array_map(fn (array $entry) => [
                'nombre' => (string) $entry['item']->name,
                'descripcion' => (string) $entry['item']->description,
                'categoria' => (string) $entry['item']->category?->name,
                'url' => route('apps.open', $entry['item']),
                'empresas' => $entry['companies'],
            ], array_slice($seen->all(), 0, self::LIMIT)),
            'limite' => self::LIMIT,
        ]);
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
