<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\BirthdayRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * «Mi cumpleaños» on /cuenta: the signed-in account saves the day and
 * month of its birthday and whether its company sees it in «Mi día» (off
 * unless the box is checked), or removes all three. The only writer of
 * those columns (App\Models\User). Per account: `person_id` plays no part.
 * Saving needs a company directory (BirthdayRequest::allows(), a 404
 * otherwise); removing does not.
 */
class BirthdayController extends Controller
{
    public function update(BirthdayRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill([
            'birthday_month' => $request->month(),
            'birthday_day' => $request->day(),
            'show_birthday' => $request->boolean('show_birthday'),
        ])->save();

        return redirect()->route('account.show')->with('status', 'Guardamos tu cumpleaños.');
    }

    /**
     * Removing has no directory gate (Ley 1581): a guest, an account
     * without a company or one whose company was deactivated can still
     * clear its own stored birthday, and only its own. Emergency accounts,
     * which never have one and have no /cuenta, get the 404 of that page.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && ! $user->is_emergency, 404);

        $user->forceFill([
            'birthday_month' => null,
            'birthday_day' => null,
            'show_birthday' => false,
        ])->save();

        return redirect()->route('account.show')->with('status', 'Quitamos tu cumpleaños.');
    }
}
