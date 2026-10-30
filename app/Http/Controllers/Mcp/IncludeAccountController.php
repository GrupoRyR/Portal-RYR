<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Mcp\AccountConfirmation;
use App\Mcp\ConsentAccounts;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * «Incluir» and «Quitar» on the consent screen of the Claude connector
 * (C2b). Both need the pending authorization the screen was shown for (its
 * `auth_token`); without it, Passport's own error.
 *
 * «Incluir» only sends the person to Microsoft to sign in with the linked
 * account (App\Mcp\AccountConfirmation); nothing is confirmed until that
 * account itself answers. «Quitar» takes a confirmation back.
 */
class IncludeAccountController extends Controller
{
    /**
     * A 404 (never a 403) for an account that is not offered, so the
     * answer does not reveal which accounts are linked.
     */
    public function store(Request $request, User $account, ConsentAccounts $consent, AccountConfirmation $confirmation): SymfonyRedirect
    {
        $approver = $request->user();
        abort_unless($approver instanceof User, 403);

        $pending = $consent->liveOrFail($request, $approver, (string) $request->input('auth_token'));
        abort_unless($consent->offers($approver, $account), 404);

        return $confirmation->toMicrosoft($approver, $account, AccountConfirmation::SILENT_ATTEMPT, $pending['fingerprint']);
    }

    public function destroy(Request $request, User $account, ConsentAccounts $consent): RedirectResponse
    {
        $approver = $request->user();
        abort_unless($approver instanceof User, 403);

        $pending = $consent->liveOrFail($request, $approver, (string) $request->input('auth_token'));
        $consent->forget($request, $account);

        return redirect($pending['return']);
    }
}
