<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\PutKpisRequest;
use App\Management\KpiPublisher;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;

/**
 * PUT /api/v1/kpis (ability `kpis:write`, docs/api.md §8): the SAP robot
 * replaces the whole set of management figures of the one company its
 * token is bound to (`integrations.company_id`). Any company named in the
 * body is ignored.
 *
 * The answer only counts the figures: it never repeats them.
 */
class KpiController extends Controller
{
    public function __invoke(PutKpisRequest $request, KpiPublisher $publisher): JsonResponse
    {
        /** @var Integration $integration */
        $integration = $request->user();
        $company = $integration->company;

        abort_if($company === null, 403, 'This token is not bound to a company.');

        $data = $request->validated();
        $count = $publisher->replace($integration, $company, [
            'period_label' => $data['period_label'],
            'as_of' => $data['as_of'],
            'source_label' => $data['source_label'],
            'items' => array_values(array_map(fn (array $item) => [
                'key' => $item['key'],
                'label' => $item['label'],
                'value' => $item['value'],
                'unit' => $item['unit'] ?? null,
                'goal' => $item['goal'] ?? null,
                'note' => $item['note'] ?? null,
            ], $data['items'])),
        ]);

        return response()->json(['data' => [
            'items' => $count,
            'as_of' => KpiPublisher::instant($data['as_of'])->toIso8601String(),
        ]]);
    }
}
