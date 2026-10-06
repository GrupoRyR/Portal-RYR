<?php

namespace App\Http\Controllers\Admin;

use App\Audit\AuditFeed;
use App\Audit\AuditFilters;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Auditoría (brief §5.2 «Registro de auditoría»,
 * docs/diseno/AdminAudit.dc.html): every change, app launch and security
 * event, newest first, filtered by GET parameters (App\Audit\AuditFilters)
 * so a view can be bookmarked.
 */
class AuditController extends Controller
{
    public function index(Request $request, AuditFeed $feed): View
    {
        $filters = AuditFilters::fromArray($request->query());
        $cursor = is_string($request->query('cursor')) ? $request->query('cursor') : null;
        $page = $feed->page($filters, $cursor);

        return view('admin.audit.index', [
            'filters' => $filters,
            'entries' => $page['entries'],
            'next' => $page['next'],
            'paged' => $cursor !== null && $cursor !== '',
            'companies' => Company::query()->orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
