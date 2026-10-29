<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Integration;
use App\Models\WorkItem;
use App\Work\WorkItemLink;
use App\Work\WorkItemWriter;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * PUT and DELETE /api/v1/work-items/{external_id} (ability `work:write`):
 * the tasks app publishes its tasks and approvals
 * (docs/contrato-tareas-aprobaciones.md §3). The route only matches a valid
 * `external_id` (WorkItem::EXTERNAL_ID_PATTERN), so any other answers 404.
 *
 * Neither call writes an audit entry (§6.5): they show in the «Último uso»
 * of the integration.
 */
class WorkItemController extends Controller
{
    /** ISO 8601 date and time with a time zone, seconds optional. */
    private const DATE_TIME = '/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-](\d{2}):(\d{2}))\z/';

    /** Control characters, line and paragraph separators, bidi overrides and isolates. */
    private const NOT_ONE_LINE = '/[\p{Cc}\p{Zl}\p{Zp}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';

    public function update(Request $request, string $externalId, WorkItemWriter $writer): JsonResponse
    {
        abort_if(strlen($request->getContent()) > WorkItem::MAX_BODY_BYTES, 413, 'The request body must not be larger than 8 KB.');

        /** @var Integration $integration */
        $integration = $request->user();
        $data = $this->validated($request, $integration);

        $saved = $writer->put($integration, $externalId, [
            'kind' => $data['kind'],
            'title' => trim($data['title']),
            'status' => $data['status'],
            'due_at' => isset($data['due_at']) ? CarbonImmutable::parse($data['due_at']) : null,
            'url' => $data['url'],
            'completable_from_portal' => $data['completable_from_portal'],
            'version' => $data['version'],
            'assignee_tenant_id' => strtolower($data['assignee']['tenant_id']),
            'assignee_oid' => strtolower($data['assignee']['oid']),
        ]);

        return response()->json(
            ['data' => ['external_id' => $externalId, ...$saved]],
            $saved['result'] === WorkItemWriter::CREATED ? 201 : 200,
        );
    }

    public function destroy(Request $request, string $externalId, WorkItemWriter $writer): Response
    {
        /** @var Integration $integration */
        $integration = $request->user();
        $writer->delete($integration, $externalId);

        return response()->noContent();
    }

    /**
     * The field rules of §3.2 and §3.7; unknown fields are dropped.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, Integration $integration): array
    {
        $authority = WorkItemLink::authorityOf($integration->app?->url);

        $validator = Validator::make($request->all(), [
            'kind' => ['bail', 'required', 'string', Rule::in(WorkItem::KINDS)],
            'title' => ['bail', 'required', 'string', 'max:'.WorkItem::MAX_TITLE, function (string $attribute, mixed $value, Closure $fail) {
                if (preg_match(self::NOT_ONE_LINE, $value) !== 0) {
                    $fail('The title field must be one line of plain text, without control characters.');
                }
            }],
            'status' => ['bail', 'required', 'string', Rule::in(WorkItem::STATUSES)],
            'due_at' => ['bail', 'nullable', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! self::isDateTimeWithZone($value)) {
                    $fail('The due at field must be an ISO 8601 date and time with a time zone.');
                }
            }],
            'url' => ['bail', 'required', 'string', 'max:'.WorkItem::MAX_URL, function (string $attribute, mixed $value, Closure $fail) use ($authority) {
                $problem = WorkItemLink::problem($value, $authority);

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
            'completable_from_portal' => ['bail', 'required', function (string $attribute, mixed $value, Closure $fail) use ($request) {
                if (! is_bool($value)) {
                    $fail('The completable from portal field must be true or false.');
                } elseif ($value && $request->input('kind') === WorkItem::KIND_APPROVAL) {
                    $fail('An approval is never completable from the portal.');
                }
            }],
            'version' => ['bail', 'required', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_int($value) || $value < 1 || $value > WorkItem::MAX_VERSION) {
                    $fail('The version field must be an integer between 1 and '.WorkItem::MAX_VERSION.'.');
                }
            }],
            'assignee' => ['required', 'array'],
            'assignee.tenant_id' => ['bail', 'required', 'string', 'uuid'],
            'assignee.oid' => ['bail', 'required', 'string', 'uuid'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $tenant = $request->input('assignee.tenant_id');

            if ($validator->errors()->hasAny(['assignee', 'assignee.tenant_id']) || ! is_string($tenant)) {
                return;
            }

            if (! Company::query()->where('tenant_id', strtolower($tenant))->exists()) {
                $validator->errors()->add('assignee', 'The assignee tenant is not the tenant of a company of the portal.');
            }
        });

        return $validator->validate();
    }

    private static function isDateTimeWithZone(string $value): bool
    {
        if (preg_match(self::DATE_TIME, $value, $part) !== 1) {
            return false;
        }

        [, $year, $month, $day, $hour, $minute] = $part;
        $second = $part[6] ?? '';
        $offsetHours = $part[7] ?? '';
        $offsetMinutes = $part[8] ?? '';

        return checkdate((int) $month, (int) $day, (int) $year)
            && (int) $hour <= 23 && (int) $minute <= 59 && (int) ($second ?: 0) <= 59
            && (int) ($offsetHours ?: 0) <= 14 && (int) ($offsetMinutes ?: 0) <= 59;
    }
}
