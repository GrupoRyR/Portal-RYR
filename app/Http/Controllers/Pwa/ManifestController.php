<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Pwa\PwaIcons;
use App\Pwa\WebManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /manifest.webmanifest, per signed-in user's company. The pages link it
 * with crossorigin="use-credentials": browsers fetch manifests without
 * cookies otherwise, and every user would get the neutral one.
 */
class ManifestController extends Controller
{
    public function __invoke(Request $request, PwaIcons $icons): JsonResponse
    {
        $user = $request->user();
        $company = $user instanceof User ? $user->company : null;

        return response()->json(
            WebManifest::for($company, $icons->for($company)),
            200,
            [
                'Content-Type' => 'application/manifest+json',
                // Depends on who is signed in: never shared, always revalidated.
                'Cache-Control' => 'private, no-cache',
                'Vary' => 'Cookie',
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
