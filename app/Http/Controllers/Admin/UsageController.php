<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Usage\UsageReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Informe de uso (brief §5.2, docs/diseno/AdminUsage.dc.html): the period
 * (`?periodo=7|30|90`) is a GET parameter so a view can be bookmarked.
 */
class UsageController extends Controller
{
    public function __invoke(Request $request, UsageReport $report): View
    {
        $period = is_string($request->query('periodo')) ? (int) $request->query('periodo') : UsageReport::DEFAULT_PERIOD;

        return view('admin.usage.index', ['report' => $report->build($period)]);
    }
}
