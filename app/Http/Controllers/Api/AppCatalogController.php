<?php

namespace App\Http\Controllers\Api;

use App\Api\AppPayload;
use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/apps (ability `catalog:read`): the catalog for external
 * systems. Hidden apps are left out; `?updated_since=` (ISO 8601,
 * inclusive) keeps only the apps whose record changed from that moment.
 */
class AppCatalogController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['updated_since' => ['sometimes', 'string', 'date']]);

        $query = App::query()
            ->where('status', '!=', App::STATUS_HIDDEN)
            ->with(['category:id,name,slug', 'companies:id,name,short_name,slug'])
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('updated_since')) {
            // Stored timestamps are app-timezone (Bogotá) strings.
            $since = CarbonImmutable::parse($request->string('updated_since'))->setTimezone(config('app.timezone'));
            $query->where('updated_at', '>=', $since->format('Y-m-d H:i:s'));
        }

        $activeCompanies = Company::active()->get(['id', 'name', 'short_name', 'slug']);

        return response()->json([
            'data' => $query->get()->map(fn (App $app) => AppPayload::from($app, $activeCompanies))->all(),
        ]);
    }
}
