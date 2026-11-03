<?php

namespace App\Mcp\Tools;

use App\Models\Announcement;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * `anuncio`: one announcement an account of the call sees right now
 * (Announcement::scopeVisibleTo(), as /anuncios/{id}), with its plain text
 * (announcements are plain text, never HTML), its dates and the person's
 * read state (Announcement::scopeWithReadAtFor()). One no account sees
 * gets NOT_FOUND, the same as one that does not exist. One query per
 * account, until one sees it.
 */
#[Name('anuncio')]
#[Description('Devuelve un anuncio del Portal RYR por su id (el de `anuncios_pendientes`): título, texto completo (texto plano), empresas, fechas de publicación y de vencimiento, si pide confirmar la lectura y si la persona ya la confirmó, y el enlace al portal. Solo si alguna cuenta de esta conexión puede verlo ahora.')]
#[IsReadOnly]
class Anuncio extends PortalTool
{
    public const NOT_FOUND = 'No encontré ese anuncio entre los que pueden ver ahora las cuentas de esta conexión.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        $id = (int) $request->validate(['id' => ['required', 'integer', 'min:1']], self::messages())['id'];

        $found = null;
        $companies = [];
        $readAt = null;

        foreach ($accounts as $account) {
            $announcement = Announcement::query()
                ->select(['id', 'title', 'body', 'starts_at', 'ends_at', 'requires_read'])
                ->withReadAtFor($account)
                ->visibleTo($account)
                ->whereKey($id)
                ->first();

            if ($announcement === null) {
                continue;
            }

            $found ??= $announcement;
            $companies[] = self::company($account);
            $readAt = self::earliest($readAt, $announcement->viewer_read_at);
        }

        if ($found === null) {
            return Response::error(self::NOT_FOUND);
        }

        return Response::structured(['anuncio' => [
            'id' => (int) $found->id,
            'titulo' => (string) $found->title,
            'texto' => (string) $found->body,
            'empresas' => array_values(array_unique($companies)),
            'publicado' => self::iso($found->starts_at),
            'publicado_texto' => self::momentLabel($found->starts_at),
            'vence' => $found->ends_at === null ? null : self::iso($found->ends_at),
            'vence_texto' => $found->ends_at === null ? null : self::momentLabel($found->ends_at),
            'requiere_lectura' => (bool) $found->requires_read,
            // The portal keeps a read state only for mandatory ones.
            'leido' => $found->requires_read ? $readAt !== null : null,
            'leido_el' => $found->requires_read && $readAt !== null ? self::iso($readAt) : null,
            'url' => route('announcements.show', $found->id),
        ]]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()
                ->min(1)
                ->description('El id del anuncio, como lo devuelve `anuncios_pendientes`.')
                ->required(),
        ];
    }

    private static function earliest(?CarbonInterface $a, ?CarbonInterface $b): ?CarbonInterface
    {
        return match (true) {
            $a === null => $b,
            $b === null => $a,
            default => $a->lessThan($b) ? $a : $b,
        };
    }
}
