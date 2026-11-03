<?php

namespace App\Mcp\Tools;

use App\Mcp\SeenByAccounts;
use App\Models\Announcement;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `anuncios_pendientes`: for each account, the announcements it sees right
 * now (Announcement::scopeVisibleTo(), as /anuncios) that require reading
 * and the person has not confirmed with any of their accounts
 * (Announcement::scopeUnreadByPersonOf(), the rule of «Leído»). Newest
 * first, at most LIMIT, each once with the companies of the accounts that
 * see it. One query per account.
 */
#[Name('anuncios_pendientes')]
#[Description('Anuncios del Portal RYR que piden confirmar la lectura y que la persona todavía no ha confirmado (con cualquiera de sus cuentas). Solo los publicados ahora para las empresas de esta conexión, del más reciente al más antiguo, máximo 20. Cada uno trae su id (para la herramienta `anuncio`), título, empresas, fecha de publicación y enlace al portal. La lectura se confirma en el portal, no desde aquí.')]
#[IsReadOnly]
class AnunciosPendientes extends PortalTool
{
    public const LIMIT = 20;

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        /** @var SeenByAccounts<Announcement> $seen */
        $seen = new SeenByAccounts;

        foreach ($accounts as $account) {
            $pending = Announcement::query()
                ->select(['id', 'title', 'starts_at'])
                ->visibleTo($account)
                ->where('requires_read', true)
                ->unreadByPersonOf($account)
                ->orderByDesc('starts_at')
                ->orderByDesc('id')
                ->limit(self::LIMIT)
                ->get();

            foreach ($pending as $announcement) {
                $seen->add($announcement->id, self::company($account), $announcement);
            }
        }

        $entries = $seen->all();
        usort($entries, fn (array $a, array $b) => [$b['item']->starts_at, $b['item']->id] <=> [$a['item']->starts_at, $a['item']->id]);

        return Response::structured([
            'anuncios' => array_map(fn (array $entry) => [
                'id' => (int) $entry['item']->id,
                'titulo' => (string) $entry['item']->title,
                'empresas' => $entry['companies'],
                'publicado' => self::iso($entry['item']->starts_at),
                'publicado_texto' => self::momentLabel($entry['item']->starts_at),
                'url' => route('announcements.show', $entry['item']->id),
            ], array_slice($entries, 0, self::LIMIT)),
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
