<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * What `mis_tareas` and `mis_aprobaciones` share: for each account, the
 * pending items of one kind its home shows (WorkItem::scopeVisibleTo():
 * its own `{tenant_id, oid}`, never `person_id`, from integrations that
 * still have `work:write` and a catalog app), labelled with the account's
 * company. An item belongs to one account, so nothing repeats. Soonest due
 * first (the overdue ones lead), those without a date last, at most LIMIT
 * (the home's 20 per group, contract §4.6). One query per account.
 *
 * Only what the home shows: the title, the due date, «Vencida» and the
 * link to the tasks app (which asks for its own sign-in).
 */
abstract class WorkItemsTool extends PortalTool
{
    public const LIMIT = 20;

    /** WorkItem::KIND_TASK or WorkItem::KIND_APPROVAL. */
    abstract protected function kind(): string;

    /** «tareas», «aprobaciones»: the key of the list in the result. */
    abstract protected function key(): string;

    public function handle(Request $request): Response|ResponseFactory
    {
        $accounts = $this->accounts($request);

        if ($accounts === null) {
            return Response::error(self::NO_ACCOUNTS);
        }

        $entries = [];

        foreach ($accounts as $account) {
            foreach ($this->soonestFirst(WorkItem::query()->visibleTo($account))->where('kind', $this->kind())->limit($this->rows(self::LIMIT))->get(['id', 'title', 'due_at', 'url']) as $item) {
                $entries[] = ['item' => $item, 'account' => $account];
            }
        }

        // Undated last, then by due date, then oldest first.
        usort($entries, fn (array $a, array $b) => [$a['item']->due_at === null, $a['item']->due_at, $a['item']->id]
            <=> [$b['item']->due_at === null, $b['item']->due_at, $b['item']->id]);

        return Response::structured([
            $this->key() => array_map(fn (array $entry) => self::present($entry['item'], $entry['account']), array_slice($entries, 0, $this->rows(self::LIMIT))),
            'limite' => $this->rows(self::LIMIT),
        ]);
    }

    /**
     * @param  Builder<WorkItem>  $query
     * @return Builder<WorkItem>
     */
    private function soonestFirst(Builder $query): Builder
    {
        return $query->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id');
    }

    /**
     * @return array{titulo: string, empresa: string, vence: ?string, vence_texto: ?string, vencida: bool, url: string}
     */
    private static function present(WorkItem $item, User $account): array
    {
        return [
            'titulo' => (string) $item->title,
            'empresa' => self::company($account),
            'vence' => $item->due_at === null ? null : self::iso($item->due_at),
            'vence_texto' => $item->due_at === null ? null : self::momentLabel($item->due_at),
            // «Vencida» of the home (§4.7): the status itself never changes.
            'vencida' => $item->due_at !== null && $item->due_at->isPast(),
            'url' => (string) $item->url,
        ];
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
