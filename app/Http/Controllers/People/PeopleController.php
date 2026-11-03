<?php

namespace App\Http\Controllers\People;

use App\Catalog\AppSearch;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\DirectoryEntry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * /personas: the directory of the user's own company, copied from Microsoft
 * every night (App\Directory\DirectorySync), by name, PER_PAGE per page,
 * with a server search (`?q=`) over name, title, area, office and email
 * that ignores accents like the home search. Accounts without a directory
 * (DirectoryEntry::companyIdFor(): emergency, no company, guests, inactive
 * company) get an empty page and no query. Plain HTML, no Livewire.
 */
class PeopleController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = new AppSearch(is_string($request->query('q')) ? $request->query('q') : null);
        $companyId = DirectoryEntry::companyIdFor($user);

        $people = $companyId === null ? null : DirectoryEntry::query()
            ->select(['id', 'display_name', 'job_title', 'department', 'office_location', 'business_phone', 'email'])
            ->where('company_id', $companyId)
            ->matching($search)
            ->orderBy('display_name')
            ->orderBy('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('people.index', [
            'people' => $people,
            'query' => $search->query(),
            'searching' => $search->isActive(),
            'companyName' => Company::displayName($user->company),
        ]);
    }
}
