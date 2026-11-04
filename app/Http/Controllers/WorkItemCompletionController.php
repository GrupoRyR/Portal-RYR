<?php

namespace App\Http\Controllers;

use App\Models\WorkItem;
use App\Work\CompletionOutcome;
use App\Work\TaskCompleter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Completar» (`POST /tareas/{workItem}/completar`,
 * docs/contrato-tareas-aprobaciones.md §6): a plain form, so it works
 * without JavaScript; back to the home's task list with the portal's
 * message (§6.4), flashed as FLASH_KEY so the home shows it in «Tareas
 * pendientes» and never mixes it with an account message (`status`).
 *
 * - Not one of the active account's own visible items (another person's,
 *   another linked account's, done, cancelled, deleted, of a revoked
 *   integration or missing): 404, so the answer never tells that it
 *   exists. `person_id` never authorizes (§4.3).
 * - Visible but not completable (an approval, a task the app did not mark
 *   completable, an integration without secret or with a base URL off
 *   its app's host): 403 and the app is never called (§7).
 * - Otherwise App\Work\TaskCompleter calls the app.
 *
 * Its own limiter (AppServiceProvider): one counter per account, apart
 * from the `throttle:N,1` routes.
 */
class WorkItemCompletionController extends Controller
{
    public const LIMITER = 'work-item-complete';

    public const PER_MINUTE = 10;

    /** The session key of «Completar»'s message (home.blade.php). */
    public const FLASH_KEY = 'work_status';

    public function store(Request $request, int $workItem, TaskCompleter $completer): RedirectResponse
    {
        $user = $request->user();

        $item = WorkItem::query()
            ->visibleTo($user)
            ->with('integration.app')
            ->whereKey($workItem)
            ->first();

        abort_if($item === null, 404);
        abort_unless($item->canBeCompletedFromPortal(), 403, CompletionOutcome::Forbidden->message());

        return redirect()->to(route('home').'#tareas')
            ->with(self::FLASH_KEY, $completer->complete($user, $item)->message());
    }
}
