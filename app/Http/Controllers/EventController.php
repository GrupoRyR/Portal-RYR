<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\View\Home\EventDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /eventos: every upcoming event the user may see, soonest first, each
 * with «Agregar a mi calendario». Plain HTML, no Livewire. «Próximas
 * fechas» and the «Ctrl K» search link to one of them with #e-{id}: they
 * hold at most HomeSearchIndex::EVENT_LIMIT of the soonest, all on this
 * page, which holds up to LIMIT and says so when more were left out.
 */
class EventController extends Controller
{
    public const LIMIT = 100;

    public function index(Request $request): View
    {
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();

        $events = Event::query()
            ->select(['id', 'title', 'description', 'location', 'starts_at', 'ends_at', 'all_day', 'repeats_yearly'])
            ->visibleTo($request->user())
            ->chronological()
            // One more than shown: whether the list was cut, in the same query.
            ->limit(self::LIMIT + 1)
            ->get();

        return view('events.index', [
            'events' => EventDate::soonestFirst($events->take(self::LIMIT), $today),
            'cut' => $events->count() > self::LIMIT,
        ]);
    }
}
