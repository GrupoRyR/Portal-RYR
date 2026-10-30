<?php

namespace App\Work;

use App\Models\Integration;
use App\Models\WorkItem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies the PUT and DELETE of the tasks app to the portal's copy
 * (docs/contrato-tareas-aprobaciones.md §3.2 to §3.4). Calls may arrive out
 * of order or repeated, so `version` decides: higher replaces every field
 * but the kind, which never changes, and may move the item to another
 * assignee (it leaves one person's portal for the other's, since
 * WorkItem::visibleTo() reads the stored assignee); equal changes nothing,
 * but answers 422 when the kind or the assignee differ, since changing the
 * assignee needs a higher version; lower is ignored.
 * A deletion trace keeps its version for WorkItem::RETENTION_DAYS.
 *
 * An integration only ever reads and writes its own `external_id`s.
 *
 * Every write first locks the row of its integration (lockIntegration()),
 * so the writes of one integration run one after the other: two first PUTs
 * of the same `external_id` cannot both create it, and two new pending
 * items cannot both pass the WorkItem::MAX_PENDING_PER_PERSON count. Other
 * integrations are not held up.
 */
final class WorkItemWriter
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const IGNORED_STALE = 'ignored_stale';

    /**
     * @param  array{kind: string, title: string, status: string, due_at: ?\DateTimeInterface, url: string, completable_from_portal: bool, version: int, assignee_tenant_id: string, assignee_oid: string}  $item
     * @return array{result: string, version: int}
     *
     * @throws ValidationException
     */
    public function put(Integration $integration, string $externalId, array $item): array
    {
        try {
            return $this->apply($integration, $externalId, $item);
        } catch (UniqueConstraintViolationException) {
            // Only reachable if a write skipped the integration lock: the
            // other call created the item, so this one compares versions.
            return $this->apply($integration, $externalId, $item);
        }
    }

    /**
     * Leaves only the version trace; nothing happens when there is no live
     * item, and repeating it changes nothing.
     */
    public function delete(Integration $integration, string $externalId): void
    {
        DB::transaction(function () use ($integration, $externalId) {
            $this->lockIntegration($integration);

            $item = WorkItem::query()
                ->where('integration_id', $integration->id)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            $item?->forceFill([
                'kind' => null,
                'title' => null,
                'status' => null,
                'due_at' => null,
                'url' => null,
                'completable_from_portal' => false,
                'assignee_tenant_id' => null,
                'assignee_oid' => null,
                'deleted_at' => now(),
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{result: string, version: int}
     */
    private function apply(Integration $integration, string $externalId, array $item): array
    {
        return DB::transaction(function () use ($integration, $externalId, $item) {
            $this->lockIntegration($integration);

            $current = WorkItem::withTrashed()
                ->where('integration_id', $integration->id)
                ->where('external_id', $externalId)
                ->lockForUpdate()
                ->first();

            if ($current !== null && $current->trashed() && $current->deleted_at->lte(now()->subDays(WorkItem::RETENTION_DAYS))) {
                $current->forceDelete();
                $current = null;
            }

            if ($current !== null) {
                if ($item['version'] < $current->version || ($current->trashed() && $item['version'] === $current->version)) {
                    return ['result' => self::IGNORED_STALE, 'version' => $current->version];
                }

                if ($item['version'] === $current->version) {
                    $this->throwIfAny($this->sameVersionErrors($item, $current));

                    return ['result' => self::UNCHANGED, 'version' => $current->version];
                }
            }

            $existing = $current !== null && ! $current->trashed() ? $current : null;
            $this->guard($integration, $externalId, $item, $existing);

            $model = $current ?? new WorkItem;
            $model->forceFill([
                ...$item,
                'integration_id' => $integration->id,
                'external_id' => $externalId,
                'deleted_at' => null,
            ]);

            if ($existing === null) {
                $model->forceFill(['created_at' => now()]);
            }

            $model->save();

            return ['result' => $existing === null ? self::CREATED : self::UPDATED, 'version' => $item['version']];
        });
    }

    /**
     * Holds the integration row until the transaction ends. The item row
     * lock alone is not enough: a row that does not exist yet cannot be
     * locked, and the pending count reads other rows. (SQLite ignores
     * FOR UPDATE; it already allows a single writer at a time.)
     */
    private function lockIntegration(Integration $integration): void
    {
        Integration::query()->whereKey($integration->getKey())->lockForUpdate()->first();
    }

    /**
     * The rules that depend on what the portal already has (§3.7): the kind
     * is fixed for an `external_id`, and a person has at most
     * WorkItem::MAX_PENDING_PER_PERSON pending items of each integration.
     * The limit applies whenever the person gains a pending item: a new
     * one, one that comes back to pending, or a pending one reassigned to
     * them.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws ValidationException
     */
    private function guard(Integration $integration, string $externalId, array $item, ?WorkItem $existing): void
    {
        $errors = $existing !== null ? $this->kindErrors($item, $existing) : [];

        $gainsPending = $item['status'] === WorkItem::STATUS_PENDING
            && ($existing?->status !== WorkItem::STATUS_PENDING || ! $this->sameAssignee($item, $existing));

        if ($gainsPending && $this->pendingOf($integration, $externalId, $item) >= WorkItem::MAX_PENDING_PER_PERSON) {
            $errors['assignee'] = 'The assignee already has '.WorkItem::MAX_PENDING_PER_PERSON.' pending items from this integration.';
        }

        $this->throwIfAny($errors);
    }

    /**
     * The kind never changes for an `external_id`, whatever the version:
     * for another kind the app deletes the item and creates it with another
     * `external_id`.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function kindErrors(array $item, WorkItem $existing): array
    {
        return $existing->kind !== $item['kind'] ? ['kind' => 'The kind cannot change for an existing item.'] : [];
    }

    /**
     * With the same version nothing is compared except kind and assignee:
     * the kind never changes, and a new assignee needs a higher version, so
     * the app is told it forgot to raise it instead of getting `unchanged`.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, string>
     */
    private function sameVersionErrors(array $item, WorkItem $existing): array
    {
        $errors = $this->kindErrors($item, $existing);

        if (! $this->sameAssignee($item, $existing)) {
            $errors['assignee'] = 'The assignee changed but the version did not; send a higher version to change it.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function sameAssignee(array $item, WorkItem $existing): bool
    {
        return [$existing->assignee_tenant_id, $existing->assignee_oid] === [$item['assignee_tenant_id'], $item['assignee_oid']];
    }

    /**
     * @param  array<string, string>  $errors
     *
     * @throws ValidationException
     */
    private function throwIfAny(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function pendingOf(Integration $integration, string $externalId, array $item): int
    {
        return WorkItem::query()
            ->where('integration_id', $integration->id)
            ->where('assignee_tenant_id', $item['assignee_tenant_id'])
            ->where('assignee_oid', $item['assignee_oid'])
            ->where('status', WorkItem::STATUS_PENDING)
            ->where('external_id', '!=', $externalId)
            ->count();
    }
}
