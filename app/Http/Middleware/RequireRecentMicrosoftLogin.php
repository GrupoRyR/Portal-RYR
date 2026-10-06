<?php

namespace App\Http\Middleware;

use App\Auth\RecentMicrosoftLogin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware "microsoft.recent": the request needs a Microsoft
 * re-authentication from the last 10 minutes (App\Auth\RecentMicrosoftLogin).
 * Emergency accounts can never pass: they have no Microsoft identity.
 */
class RequireRecentMicrosoftLogin
{
    public function __construct(private readonly RecentMicrosoftLogin $recent) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_emergency || $user->azure_oid === null) {
            return response()->view('errors.microsoft-required', [], 403);
        }

        if ($this->recent->isFresh()) {
            return $next($request);
        }

        if (! $request->isMethod('GET')) {
            abort(403);
        }

        return redirect()->route('auth.microsoft.confirm', ['return' => $request->getRequestUri()]);
    }
}
