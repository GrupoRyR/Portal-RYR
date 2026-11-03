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
 * `anuncios_recientes`: the first page of /anuncios for each account
 * (Announcement::scopeVisibleTo() and scopeOrdered(): pinned first, then
 * the newest), at most LIMIT, each once with the companies of the
 * accounts that see it. The text is plain (announcements are never HTML),
 * with the read state of the first account that sees it, for mandatory
 * ones. One query per account.
 *
 * Used by the portal assistant (App\Assistant\AssistantTools); not
 * registered in the Claude connector (App\Mcp\Servers\PortalServer) yet.
 */
#[Name('anuncios_recientes')]
#[Description('Los anuncios del Portal RYR que la cuenta ve ahora, como la lista de /anuncios: primero los fijados y luego los más recientes, máximo 10. Cada uno trae id, título, texto completo (texto plano), empresas, fecha de publicación, si está fijado, si pide confirmar la lectura y si ya se confirmó, y el enlace al portal.')]
#[IsReadOnly]
class AnunciosRecientes extends PortalTool
{
    public const LIMIT = 10;

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        /** @var SeenByAccounts<Announcement> $seen */
        $seen = new SeenByAccounts;

        foreach ($accounts as $account) {
            $announcements = Announcement::query()
                ->select(['id', 'title', 'body', 'starts_at', 'is_pinned', 'requires_read'])
                ->withReadAtFor($account)
                ->visibleTo($account)
                ->ordered()
                ->limit(self::LIMIT)
                ->get();

            foreach ($announcements as $announcement) {
                $seen->add($announcement->id, self::company($account), $announcement);
            }
        }

        $entries = $seen->all();
        // scopeOrdered(): pinned first, then the newest start, then the highest id.
        usort($entries, fn (array $a, array $b) => [$b['item']->is_pinned, $b['item']->starts_at, $b['item']->id]
            <=> [$a['item']->is_pinned, $a['item']->starts_at, $a['item']->id]);

        return Response::structured([
            'anuncios' => array_map(fn (array $entry) => [
                'id' => (int) $entry['item']->id,
                'titulo' => (string) $entry['item']->title,
                'texto' => (string) $entry['item']->body,
                'empresas' => $entry['companies'],
                'publicado' => self::iso($entry['item']->starts_at),
                'publicado_texto' => self::momentLabel($entry['item']->starts_at),
                'fijado' => (bool) $entry['item']->is_pinned,
                'requiere_lectura' => (bool) $entry['item']->requires_read,
                // The portal keeps a read state only for mandatory ones.
                'leido' => $entry['item']->requires_read ? $entry['item']->viewer_read_at !== null : null,
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
