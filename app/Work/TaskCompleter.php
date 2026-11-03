<?php

namespace App\Work;

use App\Models\Integration;
use App\Models\User;
use App\Models\WorkItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * «Completar» (docs/contrato-tareas-aprobaciones.md §6): asks the tasks app
 * to complete one task, with one signed server-to-server call during the
 * person's request (there is no queue), and applies the answer exactly as
 * §6.4 says, reading the `code` of §6.3:
 * - `200` with `completed`, or `409` with `already_done`: the item is done,
 *   same version;
 * - `403` with `not_assignee` or `not_completable`: still pending;
 * - `404` with `not_found`: removed from the portal, keeping its version
 *   trace;
 * - anything else (another `2xx`, a `404` or `403` without its code, `3xx`,
 *   `401`, `422`, `5xx`, no answer within TIMEOUT_SECONDS): still pending.
 *   Never retried, never redirected, TLS always verified.
 *
 * One press at a time per item (a cache lock). Inside it the item is read
 * again, so a press that passed the controller while another one was
 * calling is not sent twice. The answer is applied only to the version
 * seen when pressing: if the app's own PUT with a higher version landed
 * during the call, it wins and the person is told the task changed.
 *
 * Every call leaves one audit entry (§6.5, log `api`, by the person, on the
 * integration) with the `external_id`, the `request_id`, the HTTP status
 * (or `timeout`) and, when it failed, the reason: never the title, the
 * secret nor the signature. A secret that cannot be decrypted (another
 * APP_KEY) is a failure without a call (`secret_unavailable`).
 *
 * The caller checks who may press first (WorkItem::scopeVisibleTo() and
 * WorkItem::canBeCompletedFromPortal()); both are checked again here.
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
            $current = WorkItem::query()
                ->visibleTo($user)
                ->with('integration.app')
                ->whereKey($item->getKey())
                ->first();

            if ($current === null || ! $current->canBeCompletedFromPortal()) {
                return CompletionOutcome::Stale;
            }

            return $this->call($user, $current);
        } finally {
            $lock->release();
        }
    }

    private function call(User $user, WorkItem $item): CompletionOutcome
    {
        $integration = $item->integration;
        $url = (string) $integration->completionUrl((string) $item->external_id);
        $requestId = (string) Str::uuid();

        try {
            $secret = (string) $integration->signing_secret;
        } catch (DecryptException) {
            $this->audit($user, $integration, self::FAILED, $item, $requestId, null, 'secret_unavailable');

            return CompletionOutcome::Failed;
        }

        $body = json_encode([
            'assignee' => ['tenant_id' => strtolower((string) $user->tenant_id), 'oid' => strtolower((string) $user->azure_oid)],
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
                    'X-Portal-Signature' => PortalSignature::sign($secret, $timestamp, 'POST', $path, $body),
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
            $status === 200 && $code === 'completed', $status === 409 && $code === 'already_done' => [CompletionOutcome::Completed, null],
            $status === 403 && in_array($code, ['not_assignee', 'not_completable'], true) => [CompletionOutcome::Forbidden, 'forbidden'],
            $status === 404 && $code === 'not_found' => [CompletionOutcome::NotFound, 'not_found'],
            $status >= 500 => [CompletionOutcome::Failed, 'server_error'],
            default => [CompletionOutcome::Failed, 'unexpected_response'],
        };

        $this->audit($user, $integration, $outcome === CompletionOutcome::Completed ? self::COMPLETED : self::FAILED, $item, $requestId, $status ?? 'timeout', $reason);

        $applied = match ($outcome) {
            CompletionOutcome::Completed => $this->writer->markDone($item),
            CompletionOutcome::NotFound => $this->writer->deleteIfUnchanged($item),
            default => true,
        };

        return $applied ? $outcome : CompletionOutcome::Stale;
    }

    private function audit(User $user, Integration $integration, string $event, WorkItem $item, string $requestId, int|string|null $status, ?string $reason): void
    {
        activity(Integration::AUDIT_LOG)
            ->causedBy($user)
            ->performedOn($integration)
            ->event($event)
            ->withProperties(['attributes' => [
                'external_id' => (string) $item->external_id,
                'request_id' => $requestId,
                'http_status' => $status,
                ...($reason === null ? [] : ['reason' => $reason]),
            ]])
            ->log($event);
    }
}
