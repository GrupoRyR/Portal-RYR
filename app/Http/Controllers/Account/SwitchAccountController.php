<?php

namespace App\Http\Controllers\Account;

use App\Auth\AccountIntent;
use App\Auth\LinkedAccounts;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * «Cambiar de empresa»: the signed-in user moves to another account of
 * the same person (App\Auth\LinkedAccounts). Linking never gives a
 * session: this only sends the person to Microsoft to sign in with the
 * target account, silently first (prompt=none). The callback of that
 * sign-in (MicrosoftLoginController, intent `switch`) checks that the
 * target answered, retries once interactively, and only then replaces the
 * session.
 */
class SwitchAccountController extends Controller
{
    public const FAILED_MESSAGE = 'No pudimos cambiar de empresa. Sigues en tu cuenta actual.';

    public const WRONG_ACCOUNT_MESSAGE = 'Microsoft respondió con otra cuenta. Sigues en tu cuenta actual.';

    /** A silent attempt first, then at most one interactive retry. */
    public const SILENT_ATTEMPT = 1;

    public const INTERACTIVE_ATTEMPT = 2;

    /**
     * A 404 (never a 403) for anything but another usable account of the
     * same person, so the answer does not reveal which accounts are linked.
     */
    public function store(Request $request, User $user, LinkedAccounts $linkedAccounts, AccountIntent $intents): SymfonyRedirect
    {
        $current = $request->user();

        abort_unless($current instanceof User && $linkedAccounts->canSwitch($current, $user), 404);

        return self::toMicrosoft($intents, $current, $user, self::SILENT_ATTEMPT);
    }

    /**
     * Starts the `switch` intent for this attempt and sends the person to
     * Microsoft as the target account: silently on the first attempt,
     * asking for the credentials on the retry.
     *
     * The authority stays the configured one (`organizations`): the
     * Microsoft provider takes the tenant from its configuration for both
     * the authorization and the token request, so the target's tenant
     * cannot be chosen per request yet. `login_hint` names the account.
     */
    public static function toMicrosoft(AccountIntent $intents, User $current, User $target, int $attempt): SymfonyRedirect
    {
        $intents->start(AccountIntent::SWITCH, $current, $target, $attempt);

        $redirect = Socialite::driver('microsoft')
            ->setScopes(MicrosoftLoginController::SCOPES)
            ->with([
                'prompt' => $attempt === self::SILENT_ATTEMPT ? 'none' : 'login',
                'login_hint' => $target->microsoft_login_hint ?? (string) $target->email,
            ])
            ->redirect();

        $intents->bindState();

        return $redirect;
    }
}
