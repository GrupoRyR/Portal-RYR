<?php

namespace App\Http\Controllers\Api;

use App\Api\AppPayload;
use App\Api\StatusReporter;
use App\Http\Controllers\Controller;
use App\Models\App;
use App\Models\Integration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * PUT /api/v1/apps/{slug}/status (ability `status:write`): an external
 * system reports whether an app works. Any existing app may be reported,
 * hidden ones included (hiding only removes it from the home screen).
 */
class AppStatusController extends Controller
{
    public const MAX_MESSAGE = 140;

    public const MAX_SOURCE = 100;

    public function __invoke(Request $request, App $app, StatusReporter $reporter): JsonResponse
    {
        $report = $request->validate([
            'status' => ['required', 'string', Rule::in(App::EXTERNAL_STATUSES)],
            'message' => ['nullable', 'string', 'max:'.self::MAX_MESSAGE],
            'source' => ['nullable', 'string', 'max:'.self::MAX_SOURCE],
            'reference' => ['nullable', 'string', 'max:'.self::MAX_SOURCE],
        ]);

        /** @var Integration $integration */
        $integration = $request->user();
        $changed = $reporter->report($app, $integration, $report);

        return response()->json(['data' => [
            'slug' => $app->slug,
            'changed' => $changed,
            'status' => AppPayload::status($app),
        ]]);
    }
}
