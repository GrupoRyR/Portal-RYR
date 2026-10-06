<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Company;
use App\Policies\AnnouncementPolicy;
use App\View\Admin\AnnouncementAdminRow;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Announcements administration (brief §4 «Anuncios»,
 * docs/diseno/AdminAnnouncements.dc.html): status tabs with counts, a
 * company filter and the list, plus the pages of the editor
 * (App\Livewire\Admin\AnnouncementEditor). Filters are GET parameters so a
 * filtered view can be bookmarked. Announcements editors only ever see the
 * announcements of their own company.
 */
class AnnouncementController extends Controller
{
    public const PER_PAGE = 20;

    public function index(Request $request): View
    {
        $user = $request->user();
        $manager = AnnouncementPolicy::manages($user);

        // Only plain strings are read: `?estado[]=x` is ignored, not an error.
        $param = fn (string $key) => is_string($value = $request->query($key)) ? trim($value) : '';

        $companies = $manager ? Company::query()->orderBy('name')->get(['id', 'name', 'short_name', 'slug']) : collect();
        $company = $companies->firstWhere('slug', $param('empresa'));
        $status = in_array($param('estado'), Announcement::STATUSES, true) ? $param('estado') : null;

        $base = fn () => Announcement::query()
            ->manageableBy($user)
            ->when($company, fn (Builder $query, Company $company) => $query->forCompany($company));

        $counts = ['' => $base()->count()];
        foreach (Announcement::STATUSES as $value) {
            $counts[$value] = $base()->inStatus($value)->count();
        }

        $announcements = $base()
            ->when($status, fn (Builder $query, string $status) => $query->inStatus($status))
            ->with(['companies:id,name,short_name', 'author:id,name'])
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('admin.announcements.index', [
            'announcements' => $announcements,
            'rows' => $announcements->getCollection()->map(fn (Announcement $announcement) => AnnouncementAdminRow::from($announcement)),
            'counts' => $counts,
            'status' => $status,
            'company' => $company,
            'companies' => $companies,
        ]);
    }

    public function create(): View
    {
        return view('admin.announcements.edit', ['announcement' => null]);
    }

    public function edit(Announcement $announcement): View
    {
        return view('admin.announcements.edit', ['announcement' => $announcement]);
    }
}
