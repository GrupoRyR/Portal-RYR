<?php

namespace App\Pwa;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Names the signed-in user of each web response for the service worker
 * (resources/js/sw.js), which keeps one saved home page and drops it as
 * soon as a response names someone else, or nobody: a shared phone or
 * tablet never shows one person's saved page to the next.
 *
 * The key is an HMAC of the user id with the app key: stable per user,
 * meaningless to anyone else, and not the database id.
 */
final class PortalUserKey
{
    public const HEADER = 'X-Portal-User';

    public const GUEST = 'guest';

    private const LENGTH = 20;

    public static function for(?Authenticatable $user): string
    {
        if ($user === null) {
            return self::GUEST;
        }

        return substr(hash_hmac('sha256', 'pwa-user:'.$user->getAuthIdentifier(), (string) config('app.key')), 0, self::LENGTH);
    }

    /**
     * Listener of RequestHandled: tags every web response (not the API,
     * whose callers are integrations, not browsers).
     */
    public function handle(RequestHandled $event): void
    {
        if ($event->request->is('api/*')) {
            return;
        }

        $event->response->headers->set(self::HEADER, self::for($event->request->user()));
    }
}
