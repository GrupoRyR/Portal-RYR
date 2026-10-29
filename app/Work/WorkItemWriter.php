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
 * of order or repeated, so `version` decides: higher replaces every field,
 * equal changes nothing (the content is not compared), lower is ignored.
 * A deletion trace keeps its version for WorkItem::RETENTION_DAYS.
 *
 * An integration only ever reads and writes its own `external_id`s.
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
            // Two first calls raced to create the item: the other one won,
            // so this one now compares versions with it.
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
     * The rules that depend on what the portal already has (§3.7): kind
     * and assignee are fixed for an `external_id`, and a person has at most
     * WorkItem::MAX_PENDING_PER_PERSON pending items of each integration.
     *
     * @param  array<string, mixed>  $item
     *
     * @throws ValidationException
     */
    private function guard(Integration $integration, string $externalId, array $item, ?WorkItem $existing): void
    {
        $errors = [];

        if ($existing !== null && $existing->kind !== $item['kind']) {
            $errors['kind'] = 'The kind cannot change for an existing item.';
        }

        if ($existing !== null && [$existing->assignee_tenant_id, $existing->assignee_oid] !== [$item['assignee_tenant_id'], $item['assignee_oid']]) {
            $errors['assignee'] = 'The assignee cannot change for an existing item.';
        }

        $newPending = $item['status'] === WorkItem::STATUS_PENDING && $existing?->status !== WorkItem::STATUS_PENDING;

        if (! isset($errors['assignee']) && $newPending && $this->pendingOf($integration, $externalId, $item) >= WorkItem::MAX_PENDING_PER_PERSON) {
            $errors['assignee'] = 'The assignee already has '.WorkItem::MAX_PENDING_PER_PERSON.' pending items from this integration.';
        }

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
