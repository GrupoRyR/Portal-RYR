<?php

namespace App\Http\Controllers\Account;

use App\Auth\LinkedAccounts;
use App\Auth\RecentMicrosoftLogin;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\BirthdayRequest;
use App\Mcp\Connections;
use App\Mcp\McpSwitch;
use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * «Cuentas vinculadas» (GET /cuenta): the accounts of the signed-in
 * user's person, and unlinking one of them (App\Auth\LinkedAccounts),
 * their own included.
 *
 * Unlinking is a confirmation page, then a POST, both behind a fresh
 * Microsoft confirmation of the signed-in account. Anything but one of the
 * person's own accounts is a 404 (never a 403), so the answer does not
 * reveal which accounts are linked. Emergency accounts are never linked:
 * a 404 everywhere here.
 *
 * The page also holds «Mi cumpleaños» (BirthdayController) for accounts
 * with a company directory, and «Conexiones con Claude»
 * (ClaudeConnectionController) while the connector is on.
 */
class AccountController extends Controller
{
    public function show(Request $request, LinkedAccounts $linkedAccounts, McpSwitch $mcp, Connections $connections): View
    {
        $user = $request->user();

        abort_unless($user instanceof User && ! $user->is_emergency, 404);

        return view('account.show', [
            'current' => $user,
            'accounts' => $linkedAccounts->accountsOf($user),
            // «Mi cumpleaños» (BirthdayController): only with a company directory.
            'birthdayMonths' => BirthdayRequest::allows($user) ? self::months() : null,
            // «Conexiones con Claude» (ClaudeConnectionController): hidden while the connector is off.
            'claudeConnections' => $mcp->enabled() ? $connections->of($user) : null,
        ]);
    }

    /**
     * The months of the «Mi cumpleaños» select: number => «Enero».
     *
     * @return array<int, string>
     */
    private static function months(): array
    {
        $months = [];
        foreach (range(1, 12) as $month) {
            $months[$month] = Str::ucfirst(CarbonImmutable::create(2024, $month, 1)->locale('es')->isoFormat('MMMM'));
        }

        return $months;
    }

    /**
     * Without a fresh confirmation the user confirms with Microsoft first
     * and comes back here (like the microsoft.recent middleware, which
     * would answer an emergency account with a 403 instead of the 404).
     */
    public function confirmUnlink(Request $request, User $user, LinkedAccounts $linkedAccounts, RecentMicrosoftLogin $recent): View|RedirectResponse
    {
        $current = $request->user();

        abort_unless($current instanceof User && $linkedAccounts->canUnlink($current, $user), 404);

        if (! $recent->isFresh($current)) {
            return redirect()->route('auth.microsoft.confirm', ['return' => route('account.unlink.confirm', $user, false)]);
        }

        return view('account.unlink', [
            'current' => $current,
            'account' => $user->loadMissing('company'),
            // A person left with one account is dissolved: that one goes too.
            'lastLink' => $linkedAccounts->accountsOf($current)->count() <= 2,
        ]);
    }

    /**
     * microsoft.recent only guards GET requests, so the confirmation is
     * checked here: without a fresh one, back to the confirmation page,
     * which asks for it again.
     */
    public function unlink(Request $request, User $user, LinkedAccounts $linkedAccounts, RecentMicrosoftLogin $recent): RedirectResponse
    {
        $current = $request->user();

        abort_unless($current instanceof User && $linkedAccounts->canUnlink($current, $user), 404);

        if (! $recent->isFresh($current)) {
            return redirect()->route('account.unlink.confirm', $user);
        }

        $company = Company::displayName($user->company);

        // canUnlink() read without a lock: an account unlinked or moved to
        // another person since then is answered like any account not linked.
        abort_unless($linkedAccounts->unlink($user, LinkedAccounts::USER, $current), 404);

        return redirect()->route('account.show')->with('status', "Desvinculaste la cuenta de {$company}.");
    }
}
