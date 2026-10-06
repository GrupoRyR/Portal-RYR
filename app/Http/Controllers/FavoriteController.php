<?php

namespace App\Http\Controllers;

use App\Catalog\AppCatalog;
use App\Models\App;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pins and unpins apps on the home screen (`/favoritos/{slug}`). Only
 * apps the user can open; anything else is a 404.
 *
 * resources/js/home.js calls these routes with JSON. Without JavaScript the
 * star is a plain form (DELETE through `_method`), so the browser goes back
 * to the card on the home screen, keeping the current search.
 */
class FavoriteController extends Controller
{
    public function store(Request $request, App $app, AppCatalog $catalog): JsonResponse|RedirectResponse
    {
        abort_unless($catalog->canOpen($request->user(), $app), 404);

        $request->user()->favoriteApps()->syncWithoutDetaching([$app->id]);

        return $this->respond($request, $app, true);
    }

    public function destroy(Request $request, App $app, AppCatalog $catalog): JsonResponse|RedirectResponse
    {
        abort_unless($catalog->canOpen($request->user(), $app), 404);

        $request->user()->favoriteApps()->detach($app->id);

        return $this->respond($request, $app, false);
    }

    private function respond(Request $request, App $app, bool $favorite): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['favorite' => $favorite]);
        }

        $query = $request->input('q');
        $parameters = is_string($query) && trim($query) !== '' ? ['q' => $query] : [];

        return redirect()->to(route('home', $parameters).'#app-'.$app->slug);
    }
}
