<?php

namespace App\Http\Controllers;

use App\Catalog\AppCatalog;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pins and unpins apps on the home screen (`/favoritos/{slug}`). Only
 * apps the user can open; anything else is a 404.
 */
class FavoriteController extends Controller
{
    public function store(Request $request, App $app, AppCatalog $catalog): JsonResponse
    {
        abort_unless($catalog->canOpen($request->user(), $app), 404);

        $request->user()->favoriteApps()->syncWithoutDetaching([$app->id]);

        return response()->json(['favorite' => true]);
    }

    public function destroy(Request $request, App $app, AppCatalog $catalog): JsonResponse
    {
        abort_unless($catalog->canOpen($request->user(), $app), 404);

        $request->user()->favoriteApps()->detach($app->id);

        return response()->json(['favorite' => false]);
    }
}
