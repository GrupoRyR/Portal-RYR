<?php

namespace App\Api;

use App\Models\App;
use App\Models\AppStatusEvent;
use App\Models\Integration;
use Illuminate\Support\Facades\DB;

/**
 * Stores a status reported by an external system (PUT
 * /api/v1/apps/{slug}/status, brief §6b).
 *
 * - degraded/down turn the card «Con fallas», operational brings it back;
 *   maintenance set by TI always wins when the card is drawn, but the
 *   report is still stored, so it shows once maintenance ends.
 * - Idempotent: the same status and message as the current, unexpired
 *   report is not a change. It only refreshes `external_status_at` (so the
 *   failure does not expire while the system keeps reporting it); no
 *   event, no audit entry, `updated_at` untouched.
 * - Every change adds an `app_status_events` row and an activity log entry
 *   (log `api`) caused by the integration, shown in Auditoría under «API».
 */
final class StatusReporter
{
    /** Activity log name of everything done through or about the API. */
    public const AUDIT_LOG = 'api';

    /**
     * @param  array{status: string, message?: ?string, source?: ?string, reference?: ?string}  $report
     * @return bool Whether it was a change.
     */
    public function report(App $app, Integration $integration, array $report): bool
    {
        $status = $report['status'];
        $message = self::text($report['message'] ?? null);
        $now = now();

        if ($app->hasCurrentReport() && $app->external_status === $status && $app->external_status_message === $message) {
            App::query()->whereKey($app->getKey())->toBase()->update(['external_status_at' => $now]);
            $app->external_status_at = $now;
            $app->syncOriginalAttribute('external_status_at');

            return false;
        }

        DB::transaction(function () use ($app, $integration, $report, $status, $message, $now) {
            $old = $app->only(['external_status', 'external_status_message']);

            $app->forceFill([
                'external_status' => $status,
                'external_status_message' => $message,
                'external_status_at' => $now,
            ])->save();

            AppStatusEvent::create([
                'app_id' => $app->id,
                'integration_id' => $integration->id,
                'status' => $status,
                'message' => $message,
                'source' => self::text($report['source'] ?? null),
                'reference' => self::text($report['reference'] ?? null),
            ]);

            activity(self::AUDIT_LOG)
                ->causedBy($integration)
                ->performedOn($app)
                ->event('api.status_reported')
                ->withProperties(array_filter([
                    'old' => $old,
                    'attributes' => ['external_status' => $status, 'external_status_message' => $message],
                    'source' => self::text($report['source'] ?? null),
                    'reference' => self::text($report['reference'] ?? null),
                ], fn ($value) => $value !== null))
                ->log('api.status_reported');
        });

        return true;
    }

    private static function text(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
