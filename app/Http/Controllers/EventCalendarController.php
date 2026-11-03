<?php

namespace App\Http\Controllers;

use App\Calendar\EventCalendarFile;
use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * «Agregar a mi calendario»: the .ics file of one event the user can see
 * right now (App\Models\Event::scopeVisibleTo). Any other one is not found,
 * the same as one that does not exist, so the answer never tells that an
 * event of another company exists.
 */
class EventCalendarController extends Controller
{
    public function __invoke(Request $request, Event $event): Response
    {
        $found = Event::query()->visibleTo($request->user())->whereKey($event->getKey())->first();

        abort_if($found === null, 404);

        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: $request->getHost();

        return response(EventCalendarFile::for($found, $host, now()), 200, [
            'Content-Type' => EventCalendarFile::CONTENT_TYPE,
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, EventCalendarFile::filename($found)),
        ]);
    }
}
