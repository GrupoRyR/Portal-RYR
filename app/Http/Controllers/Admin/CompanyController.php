<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Companies administration (brief, section 5.1). The list and forms arrive
 * in T6b; for now the page only anchors the admin shell.
 */
class CompanyController extends Controller
{
    public function index(): View
    {
        return view('admin.companies.index');
    }
}
