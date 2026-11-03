<?php

namespace App\Http\Controllers;

use App\View\Home\HomePageBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The home screen: the user's apps, favorites, recents, «Mi día» and the
 * company blocks. Without JavaScript, `?q=` filters the apps on the server
 * and `?noticias=` picks the «Noticias» tab.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, HomePageBuilder $builder): View
    {
        return view('home', [
            'page' => $builder->build(
                $request->user(),
                self::text($request->query('q')),
                newsTab: self::text($request->query('noticias')),
            ),
        ]);
    }

    /** A query value, or null when it is missing or not a single text (`?q[]=`). */
    private static function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
