<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * New-company wizard (brief 5.1): data and brand, admin consent, APP-…
 * groups, activation. A company stays inactive until the last step, so an
 * unfinished one can be resumed from the list.
 */
class CompanyWizardController extends Controller
{
    public function create(): View
    {
        return view('admin.companies.wizard', ['company' => null]);
    }

    public function resume(Company $company): View|RedirectResponse
    {
        if ($company->is_active) {
            return redirect()->route('admin.companies.show', $company);
        }

        return view('admin.companies.wizard', ['company' => $company]);
    }
}
