<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\View\Home\AppsView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves the view of «Mis aplicaciones» the signed-in account chose
 * (`PUT /inicio/vista`, App\View\Home\AppsView) and goes back to the card,
 * keeping the current search. The selector is a plain form, so it works
 * without JavaScript. The only writer of `users.apps_view`
 * (App\Models\User): an unknown value is a validation error and saves
 * nothing (422 for JSON).
 */
class AppsViewController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $back = $this->home($request);

        $validator = Validator::make($request->only('view'), [
            'view' => ['required', 'string', Rule::enum(AppsView::class)],
        ]);

        if ($validator->fails()) {
            throw (new ValidationException($validator))->redirectTo($back);
        }

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['apps_view' => $validator->validated()['view']])->save();

        return redirect()->to($back);
    }

    /** The home at the apps card, with the search that was on. */
    private function home(Request $request): string
    {
        $query = $request->input('q');
        $parameters = is_string($query) && trim($query) !== '' ? ['q' => $query] : [];

        return route('home', $parameters).'#mis-aplicaciones';
    }
}
