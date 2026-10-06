<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;

/**
 * Companies administration (brief, section 5.1): the list with the editor
 * of the selected company. Creating companies and changing tenants are T6c.
 */
class CompanyController extends Controller
{
    public function index(): View
    {
        $companies = $this->companies();

        return view('admin.companies.index', [
            'companies' => $companies,
            'selected' => $companies->first(),
        ]);
    }

    public function show(Company $company): View
    {
        return view('admin.companies.index', [
            'companies' => $this->companies(),
            'selected' => $company,
        ]);
    }

    /**
     * @return Collection<int, Company>
     */
    private function companies()
    {
        return Company::query()->orderBy('name')->get();
    }
}
