<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\View\Home\AnnouncementCard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * «Ver todos»: every announcement the user may see right now, pinned
 * first, ten per page. Plain HTML, no Livewire. The phone banner links to
 * one of them with #a-{id}.
 */
class AnnouncementController extends Controller
{
    public const PER_PAGE = 10;

    public function index(Request $request): View
    {
        $announcements = Announcement::query()
            ->visibleTo($request->user())
            ->ordered()
            ->paginate(self::PER_PAGE, ['id', 'title', 'body', 'image_path', 'starts_at', 'is_pinned']);

        return view('announcements.index', [
            'announcements' => $announcements,
            'cards' => $announcements->getCollection()->map(fn (Announcement $announcement) => AnnouncementCard::from($announcement)),
        ]);
    }
}
