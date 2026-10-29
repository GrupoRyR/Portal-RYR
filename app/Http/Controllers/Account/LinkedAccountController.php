<?php

namespace App\Http\Controllers\Account;

use App\Auth\AccountIntent;
use App\Auth\RecentMicrosoftLogin;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * «Vincular otra cuenta»: the signed-in user, freshly confirmed with
 * Microsoft, signs in to their account of another company so that both
 * are linked (App\Auth\LinkedAccounts). The callback of that sign-in
 * (MicrosoftLoginController, intent `link`) checks and links; nothing is
 * linked here.
 */
class LinkedAccountController extends Controller
{
    /**
     * Behind microsoft.recent: only Microsoft accounts with a fresh
     * confirmation get here.
     */
    public function create(Request $request): View
    {
        $user = $request->user();

        return view('account.link', [
            'company' => $user->company,
            'linked' => $user->linkedAccounts()->with('company')->orderBy('id')->get(),
        ]);
    }

    /**
     * microsoft.recent only guards GET requests, so the confirmation is
     * checked here: without a fresh one, back to the page, which asks for
     * it again.
     */
    public function store(Request $request, RecentMicrosoftLogin $recent, AccountIntent $intents): SymfonyRedirect|RedirectResponse|Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_emergency || $user->azure_oid === null) {
            return response()->view('errors.microsoft-required', [], 403);
        }

        if (! $recent->isFresh($user)) {
            return redirect()->route('account.link');
        }

        $intents->start(AccountIntent::LINK, $user);

        // The person picks the other account: no hint of this one.
        $redirect = Socialite::driver('microsoft')
            ->setScopes(MicrosoftLoginController::SCOPES)
            ->with(['prompt' => 'select_account'])
            ->redirect();

        $intents->bindState();

        return $redirect;
    }
}
