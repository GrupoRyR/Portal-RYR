<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\PushSubscriptionRequest;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\PushSettings;
use App\Push\PushSubscriptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * «Avisos en el celular» on /cuenta: the page's script posts the browser's
 * subscription here (store) and removes it (destroy). JSON only, with the
 * CSRF token in X-CSRF-TOKEN.
 *
 * A 404 while the VAPID settings are missing (App\Push\PushSettings) and
 * for accounts without a company (the emergency account), which see no
 * announcements and so would never get one.
 */
class PushSubscriptionController extends Controller
{
    public function store(PushSubscriptionRequest $request, PushSettings $settings, PushSubscriptions $subscriptions): Response
    {
        $user = $this->allowedUser($request, $settings);

        $subscriptions->subscribe(
            $user,
            (string) $request->validated('endpoint'),
            (string) $request->validated('keys.p256dh'),
            (string) $request->validated('keys.auth'),
            (string) ($request->validated('contentEncoding') ?? PushSubscription::ENCODINGS[0]),
            $request->userAgent(),
        );

        // So signing out forgets this browser (LogoutController).
        PushSubscriptions::remember($request->session(), (string) $request->validated('endpoint'), subscribed: true);

        return response()->noContent();
    }

    public function destroy(Request $request, PushSettings $settings, PushSubscriptions $subscriptions): Response
    {
        $user = $this->allowedUser($request, $settings);

        $endpoint = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushSubscriptions::MAX_ENDPOINT_LENGTH],
        ])['endpoint'];

        $subscriptions->unsubscribe($user, $endpoint);
        PushSubscriptions::remember($request->session(), $endpoint, subscribed: false);

        return response()->noContent();
    }

    private function allowedUser(Request $request, PushSettings $settings): User
    {
        $user = $request->user();

        abort_unless($settings->enabled() && $user instanceof User && ! $user->is_emergency && $user->company_id !== null, 404);

        return $user;
    }
}
