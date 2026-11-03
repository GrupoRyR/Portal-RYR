<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\View\Home\AnnouncementCard;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Ver todos»: every announcement the user may see right now, pinned
 * first, Announcement::PER_PAGE per page, with the user's read state of
 * mandatory ones («Confirmar lectura»). Plain HTML, no Livewire. The
 * phone banner links to one of them with #a-{id}; the «Ctrl K» search
 * links to show(), which finds its page.
 */
class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $announcements = Announcement::query()
            ->select(['id', 'title', 'body', 'image_path', 'starts_at', 'is_pinned', 'requires_read'])
            ->withReadAtFor($request->user())
            ->visibleTo($request->user())
            ->ordered()
            ->paginate(Announcement::PER_PAGE);

        return view('announcements.index', [
            'announcements' => $announcements,
            'cards' => $announcements->getCollection()->map(fn (Announcement $announcement) => AnnouncementCard::from($announcement)),
        ]);
    }

    /**
     * To the announcement on its page of index(), worked out now rather
     * than when the link was made: the link can come from a saved copy of
     * the home page. One the user cannot see right now is not found, the
     * same as one that does not exist.
     */
    public function show(Request $request, int $announcement): RedirectResponse
    {
        $user = $request->user();

        $target = Announcement::query()
            ->visibleTo($user)
            ->whereKey($announcement)
            ->first(['id', 'starts_at', 'is_pinned']);

        abort_if($target === null, 404);

        // How many come before it in Announcement::scopeOrdered(): pinned
        // first, then the newest start, then the highest id.
        $before = Announcement::query()
            ->visibleTo($user)
            ->where(fn (Builder $query) => $query
                ->where('is_pinned', '>', $target->is_pinned)
                ->orWhere(fn (Builder $query) => $query
                    ->where('is_pinned', $target->is_pinned)
                    ->where('starts_at', '>', $target->starts_at))
                ->orWhere(fn (Builder $query) => $query
                    ->where('is_pinned', $target->is_pinned)
                    ->where('starts_at', $target->starts_at)
                    ->where('id', '>', $target->id)))
            ->count();

        $page = intdiv($before, Announcement::PER_PAGE) + 1;

        return redirect()->to(route('announcements.index', $page > 1 ? ['page' => $page] : []).'#a-'.$target->id);
    }
}
