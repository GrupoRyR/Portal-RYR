<?php

namespace App\Http\Controllers\Agent;

use App\Agent\AgentSettings;
use App\Agent\Manifest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /.well-known/agente-ryr.json: the Portal's public tool manifest for the
 * agent. It carries no data and no secrets, and answers 503 (closed) when
 * the Portal's https origin is not configured (AGENT_APP_URL or APP_URL).
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $url = AgentSettings::appUrl();

        if ($url === null) {
            return response()->json(['error' => 'The https origin of the app is not configured (AGENT_APP_URL or APP_URL).'], 503);
        }

        return response()->json(Manifest::build($url), 200, ['Cache-Control' => 'public, max-age=300'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
