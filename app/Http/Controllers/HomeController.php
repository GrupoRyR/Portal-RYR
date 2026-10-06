<?php

namespace App\Http\Controllers;

use App\View\Home\HomePageBuilder;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The home screen: the user's apps, favorites, recents and Microsoft 365
 * links. `?q=` filters on the server when JavaScript is not available.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, HomePageBuilder $builder): View
    {
        $query = $request->query('q');

        return view('home', [
            'page' => $builder->build($request->user(), is_string($query) ? $query : null),
        ]);
    }
}
