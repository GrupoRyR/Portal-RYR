<?php

namespace App\Work;

use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;

/**
 * «Completar» (docs/contrato-tareas-aprobaciones.md §6): asks the tasks app
 * to complete one task, with one signed server-to-server call during the
 * person's request (there is no queue), and applies the answer (§6.4):
 * - `2xx`, or `409` with `already_done`: the item is done, same version;
 * - `403`: still pending;
 * - `404`: removed from the portal, keeping its version trace;
 * - anything else (`3xx`, `401`, `422`, `5xx`, no answer within
 *   TIMEOUT_SECONDS): still pending. Never retried, never redirected, TLS
 *   always verified.
 *
 * One press at a time per item (a cache lock). Every call leaves one audit
 * entry (§6.5, log `api`, by the person, on the integration) with the
 * `external_id`, the `request_id`, the HTTP status (or `timeout`) and, when
 * it failed, the reason: never the title, the secret nor the signature.
 *
 * The caller decides who may press: the item must be visible to the
 * account (WorkItem::scopeVisibleTo()) and WorkItem::canBeCompletedFromPortal().
 */
final class TaskCompleter
{
    public const TIMEOUT_SECONDS = 5;

    /** Longer than the call, so a slow app never lets a second press in. */
    public const LOCK_SECONDS = 15;

    public const COMPLETED = 'work_item.completed';

    public const FAILED = 'work_item.complete_failed';

    public function __construct(private readonly WorkItemWriter $writer) {}

    public static function lockKey(WorkItem $item): string
    {
        return 'work-item-complete:'.$item->getKey();
    }

    public function complete(User $user, WorkItem $item): CompletionOutcome
    {
        $lock = Cache::lock(self::lockKey($item), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return CompletionOutcome::Busy;
        }

        try {
            return $this->call($user, $item);
        } finally {
            $lock->release();
        }
    }

    private function call(User $user, WorkItem $item): CompletionOutcome
    {
        $integration = $item->integration;
        $url = $integration->completionUrl((string) $item->external_id);

        if ($url === null || $user->tenant_id === null || $user->azure_oid === null) {
            throw new LogicException('This task cannot be completed from the portal.');
        }

        $requestId = (string) Str::uuid();
        $body = json_encode([
            'assignee' => ['tenant_id' => strtolower($user->tenant_id), 'oid' => strtolower($user->azure_oid)],
            'completed_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
            'request_id' => $requestId,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->getTimestamp();
        // Signed exactly as sent, still percent-encoded.
        $path = (string) parse_url($url, PHP_URL_PATH);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders([
                    'X-Portal-Timestamp' => $timestamp,
                    'X-Portal-Signature' => PortalSignature::sign((string) $integration->signing_secret, $timestamp, 'POST', $path, $body),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
            $status = $response->status();
            $code = $response->json('code');
        } catch (ConnectionException) {
            $status = null;
            $code = null;
        }

        [$outcome, $reason] = match (true) {
            $status === null => [CompletionOutcome::Failed, 'timeout'],
            $status >= 200 && $status < 300, $status === 409 && $code === 'already_done' => [CompletionOutcome::Completed, null],
            $status === 403 => [CompletionOutcome::Forbidden, 'forbidden'],
            $status === 404 => [CompletionOutcome::NotFound, 'not_found'],
            $status >= 500 => [CompletionOutcome::Failed, 'server_error'],
            default => [CompletionOutcome::Failed, 'unexpected_response'],
        };

        match ($outcome) {
            CompletionOutcome::Completed => $this->writer->markDone($item),
            CompletionOutcome::NotFound => $this->writer->delete($integration, (string) $item->external_id),
            default => null,
        };

        $this->audit($user, $integration, $outcome === CompletionOutcome::Completed ? self::COMPLETED : self::FAILED, [
            'external_id' => (string) $item->external_id,
            'request_id' => $requestId,
            'http_status' => $status ?? 'timeout',
            ...($reason === null ? [] : ['reason' => $reason]),
        ]);

        return $outcome;
    }

    /**
     * @param  array<string, int|string>  $attributes
     */
    private function audit(User $user, Integration $integration, string $event, array $attributes): void
    {
        activity(Integration::AUDIT_LOG)
            ->causedBy($user)
            ->performedOn($integration)
            ->event($event)
            ->withProperties(['attributes' => $attributes])
            ->log($event);
    }
}
