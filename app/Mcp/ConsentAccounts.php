<?php

namespace App\Mcp;

use App\Auth\LinkedAccounts;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User as BridgeUser;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;

/**
 * The linked accounts confirmed with Microsoft for the pending
 * authorization of the consent screen (C2b), kept in the session until
 * «Aprobar» stores them (App\Mcp\Connections).
 *
 * Passport keeps one pending authorization per session: the request
 * (`authRequest`) and a random `authToken` that the consent form sends
 * back; every GET /oauth/authorize replaces both. The list is bound to that
 * `authToken` and to a fingerprint of the request (client, account,
 * redirect, state, PKCE challenge, scopes). It follows a new screen of the
 * same request (the way back from Microsoft) and starts empty for any other
 * request, client or account. Once Passport ends the pending authorization
 * (approved, denied, or a wrong `auth_token`), it matches nothing.
 *
 * Linking only offers: an offered account is used only after a Microsoft
 * sign-in of that very account (App\Mcp\AccountConfirmation).
 */
class ConsentAccounts
{
    /** ['auth_token', 'fingerprint', 'user_id', 'client', 'return', 'accounts' => [id => confirmed at (timestamp)]]. */
    public const SESSION_KEY = 'mcp.consent';

    /** Where Passport keeps the pending authorization (AuthorizationController). */
    private const AUTH_TOKEN_KEY = 'authToken';

    private const AUTH_REQUEST_KEY = 'authRequest';

    public function __construct(
        private readonly LinkedAccounts $links,
        private readonly ConnectionAccounts $accounts,
    ) {}

    /**
     * Called as Passport shows the consent screen, once it stored the new
     * pending authorization. Keeps the confirmations only when the previous
     * pending authorization was still the one they were bound to and the
     * request is the same; returns the data the screen shows.
     *
     * @return array{offered: Collection<int, User>, confirmed: array<int, CarbonImmutable>}
     */
    public function shown(Request $request, User $user, ?string $previousAuthToken): array
    {
        $session = $request->session();
        $authRequest = self::authRequest($session);
        $authToken = $session->get(self::AUTH_TOKEN_KEY);

        if ($authRequest === null || ! is_string($authToken)) {
            $session->forget(self::SESSION_KEY);

            return ['offered' => new Collection, 'confirmed' => []];
        }

        $state = $session->get(self::SESSION_KEY);
        $fingerprint = self::fingerprint($authRequest);
        $same = is_array($state)
            && is_string($previousAuthToken)
            && is_string($state['auth_token'] ?? null)
            && hash_equals($state['auth_token'], $previousAuthToken)
            && hash_equals((string) ($state['fingerprint'] ?? ''), $fingerprint)
            && ($state['user_id'] ?? null) === $user->getKey();

        $session->put(self::SESSION_KEY, [
            'auth_token' => $authToken,
            'fingerprint' => $fingerprint,
            'user_id' => $user->getKey(),
            'client' => (string) $authRequest->getClient()->getName(),
            'return' => $request->fullUrl(),
            'accounts' => $same && is_array($state['accounts'] ?? null) ? $state['accounts'] : [],
        ]);

        return ['offered' => $this->offered($user), 'confirmed' => $this->confirmed($request, $user)];
    }

    /**
     * The linked accounts the approving account may confirm for the
     * connection: what a company switch would allow
     * (LinkedAccounts::switchTargets()) that may also connect.
     *
     * @return Collection<int, User>
     */
    public function offered(User $approver): Collection
    {
        return $this->links->switchTargets($approver)
            ->filter(fn (User $account) => $this->accounts->mayConnect($account))
            ->values();
    }

    public function offers(User $approver, User $account): bool
    {
        return $this->links->canSwitch($approver, $account) && $this->accounts->mayConnect($account);
    }

    /**
     * The state of the pending authorization this session shows, or null
     * when Passport no longer has it (approved, denied, replaced, lost) or
     * it belongs to another account. With `$authToken`, the one a form sent
     * back must be the live one too.
     *
     * @return array{auth_token: string, fingerprint: string, user_id: int, client: string, return: string, accounts: array<int, int>}|null
     */
    public function live(Request $request, User $user, ?string $authToken = null): ?array
    {
        $session = $request->session();
        $state = $session->get(self::SESSION_KEY);
        $live = $session->get(self::AUTH_TOKEN_KEY);
        $authRequest = self::authRequest($session);

        if (! is_array($state)
            || ! is_string($live)
            || $authRequest === null
            || ! is_string($state['auth_token'] ?? null)
            || ! hash_equals($live, $state['auth_token'])
            || ($authToken !== null && ! hash_equals($live, $authToken))
            || ! hash_equals((string) ($state['fingerprint'] ?? ''), self::fingerprint($authRequest))
            || ($state['user_id'] ?? null) !== $user->getKey()) {
            return null;
        }

        return $state;
    }

    /**
     * {@see live()}, or Passport's own error for a pending authorization
     * that is gone: never a token.
     *
     * @return array{auth_token: string, fingerprint: string, user_id: int, client: string, return: string, accounts: array<int, int>}
     *
     * @throws InvalidAuthTokenException
     */
    public function liveOrFail(Request $request, User $user, ?string $authToken = null): array
    {
        return $this->live($request, $user, $authToken) ?? throw InvalidAuthTokenException::different();
    }

    /**
     * When each account was confirmed for the live pending authorization.
     *
     * @return array<int, CarbonImmutable>
     */
    public function confirmed(Request $request, User $user): array
    {
        $accounts = $this->live($request, $user)['accounts'] ?? [];

        return array_map(fn (int $at) => CarbonImmutable::createFromTimestamp($at, config('app.timezone')), $accounts);
    }

    /**
     * Records the confirmation of a linked account. The caller checked
     * {@see live()} and the Microsoft answer.
     */
    public function confirm(Request $request, User $account): void
    {
        $request->session()->put(self::SESSION_KEY.'.accounts.'.$account->getKey(), now()->getTimestamp());
    }

    public function forget(Request $request, User $account): void
    {
        $request->session()->forget(self::SESSION_KEY.'.accounts.'.$account->getKey());
    }

    /**
     * Ends the list as «Aprobar» uses the pending authorization: the
     * confirmations bound to it (its `auth_token`, already checked by
     * Passport, and its request), or none.
     *
     * @return array<int, CarbonImmutable>
     */
    public function pullForApproval(Request $request, AuthorizationRequestInterface $authRequest, string $authToken, User $user): array
    {
        $state = $request->session()->pull(self::SESSION_KEY);

        if (! is_array($state)
            || ! is_string($state['auth_token'] ?? null)
            || ! hash_equals($state['auth_token'], $authToken)
            || ! hash_equals((string) ($state['fingerprint'] ?? ''), self::fingerprint($authRequest))
            || ($state['user_id'] ?? null) !== $user->getKey()
            || ! is_array($state['accounts'] ?? null)) {
            return [];
        }

        return array_map(fn (int $at) => CarbonImmutable::createFromTimestamp($at), $state['accounts']);
    }

    /**
     * Passport's pending authorization request, read without taking it
     * (Passport's RetrievesAuthRequestFromSession pulls it), with the same
     * allowed classes.
     */
    private static function authRequest(Session $session): ?AuthorizationRequestInterface
    {
        $serialized = $session->get(self::AUTH_REQUEST_KEY);

        if (! is_string($serialized)) {
            return null;
        }

        $authRequest = unserialize($serialized, ['allowed_classes' => [
            AuthorizationRequest::class,
            Client::class,
            Scope::class,
            BridgeUser::class,
        ]]);

        return $authRequest instanceof AuthorizationRequestInterface ? $authRequest : null;
    }

    private static function fingerprint(AuthorizationRequestInterface $authRequest): string
    {
        return hash('sha256', (string) json_encode([
            $authRequest->getClient()->getIdentifier(),
            (string) $authRequest->getUser()?->getIdentifier(),
            $authRequest->getRedirectUri(),
            $authRequest->getState(),
            $authRequest->getCodeChallenge(),
            $authRequest->getCodeChallengeMethod(),
            array_map(fn (ScopeEntityInterface $scope) => $scope->getIdentifier(), $authRequest->getScopes()),
        ]));
    }
}
