<?php

namespace App\Mcp;

use App\Audit\SecurityLog;
use App\Auth\AccountIntent;
use App\Auth\LoginFailed;
use App\Auth\MicrosoftIdentity;
use App\Auth\MicrosoftLogin;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Exceptions\InvalidAuthTokenException;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Confirms a linked account for the pending Claude connection (C2b), the
 * way a company switch does: a Microsoft sign-in of that very account
 * (`mcp_include` intent, prompt=none with login_hint, at most one retry
 * with prompt=login). The answer counts only when it is the target account
 * itself (tenant and object ID), it passes every rule of a sign-in
 * (MicrosoftLogin::preview() and admit(), without recording a sign-in) and
 * it is still offered (App\Mcp\ConsentAccounts). Then it is confirmed for
 * the pending authorization only, until «Aprobar».
 *
 * The session never changes its user. Anything else confirms nothing and
 * goes back to the consent screen with a message; a pending authorization
 * that is gone gets Passport's own error.
 *
 * As with the switch, admit() refreshes the target's row with its proven
 * Microsoft data (email, company, guest flag, profile, groups, roles), and
 * a check after it may still refuse.
 */
class AccountConfirmation
{
    /** Activity log events (log `security`). */
    public const INCLUDED = 'mcp.account_included';

    public const INCLUDE_FAILED = 'mcp.account_include_failed';

    /** A silent attempt first, then at most one interactive retry. */
    public const SILENT_ATTEMPT = 1;

    public const INTERACTIVE_ATTEMPT = 2;

    public const FAILED_MESSAGE = 'No pudimos confirmar esa cuenta. No se incluyó en la conexión.';

    public const WRONG_ACCOUNT_MESSAGE = 'Microsoft respondió con otra cuenta. No se incluyó en la conexión.';

    public function __construct(
        private readonly ConsentAccounts $consent,
        private readonly AccountIntent $intents,
        private readonly MicrosoftLogin $login,
        private readonly ConnectionAccounts $accounts,
    ) {}

    /**
     * Starts the `mcp_include` intent for this attempt and sends the person
     * to Microsoft as the target account: silently on the first attempt,
     * asking for the credentials on the retry.
     *
     * The intent carries the fingerprint of the pending authorization it
     * was started for (ConsentAccounts), not its `auth_token`, which every
     * new consent screen of the same request changes. The callback only
     * confirms for that same pending authorization.
     */
    public function toMicrosoft(User $approver, User $target, int $attempt, string $binding): SymfonyRedirect
    {
        $this->intents->start(AccountIntent::MCP_INCLUDE, $approver, $target, $attempt, $binding);

        $redirect = Socialite::driver('microsoft')
            ->setScopes(MicrosoftLoginController::SCOPES)
            ->with([
                'prompt' => $attempt === self::SILENT_ATTEMPT ? 'none' : 'login',
                'login_hint' => $target->microsoft_login_hint ?? (string) $target->email,
            ])
            ->redirect();

        $this->intents->bindState();

        return $redirect;
    }

    /**
     * Microsoft answered the confirmation with an account.
     *
     * @param  array{target_id: int|null, attempt: int, binding?: string|null}  $intent
     *
     * @throws InvalidAuthTokenException
     */
    public function answered(Request $request, User $approver, array $intent): RedirectResponse
    {
        $attempt = (int) ($intent['attempt'] ?? 0);
        $target = User::query()->find($intent['target_id'] ?? null);
        $pending = $this->pendingOrFail($request, $approver, $intent, $target, $attempt);

        if ($target === null || ! $this->consent->offers($approver, $target)) {
            return $this->failed($pending, $approver, $target, 'target_unavailable', $attempt, null);
        }

        $identity = null;

        try {
            $driver = Socialite::driver('microsoft');
            $identity = MicrosoftIdentity::fromSocialite($driver->user(), $driver->getClaims());

            if (! MicrosoftLoginController::isAccount($target, $identity)) {
                return $this->failed($pending, $approver, $target, 'wrong_account', $attempt, $identity->tenantId, self::WRONG_ACCOUNT_MESSAGE);
            }

            // Every rule of a sign-in, before anything is written.
            if (! $this->accounts->mayConnect($this->login->preview($identity))) {
                return $this->failed($pending, $approver, $target, 'account_not_allowed', $attempt, $identity->tenantId);
            }

            ['user' => $admitted, 'roleChanges' => $roleChanges] = $this->login->admit($identity);

            // No sign-in event carries them here (as when linking).
            if ($roleChanges['added'] !== [] || $roleChanges['removed'] !== []) {
                SecurityLog::rolesSynced($admitted, $roleChanges);
            }
        } catch (LoginFailed $e) {
            return $this->failed($pending, $approver, $target, $e->reason, $attempt, $identity?->tenantId);
        } catch (Throwable $e) {
            Log::warning('Confirming a linked account for a Claude connection failed.', [
                'user_id' => $approver->id,
                'target_id' => $target->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->failed($pending, $approver, $target, 'provider_error', $attempt, $identity?->tenantId);
        }

        // admit() may have moved or unlinked it: still the target, still offered.
        if (! $admitted->is($target) || ! $this->consent->offers($approver->fresh() ?? $approver, $admitted)) {
            return $this->failed($pending, $approver, $target, 'target_unavailable', $attempt, $identity->tenantId);
        }

        $this->consent->confirm($request, $admitted);
        $this->audit(self::INCLUDED, $approver, $admitted, [
            'client' => $pending['client'],
            'attempt' => $attempt,
            'included_user_id' => $admitted->getKey(),
            'included_company' => $admitted->company?->name,
            'included_email' => $admitted->email,
        ]);

        return redirect($pending['return'])
            ->with('status', 'Confirmaste tu cuenta de '.Company::displayName($admitted->company).'. Se incluirá al aprobar.');
    }

    /**
     * Microsoft sent back an `error` for the confirmation. When the silent
     * attempt only needs the person, it is retried once asking for the
     * credentials; anything else, or a failed retry, confirms nothing.
     *
     * @param  array{target_id: int|null, attempt: int, binding?: string|null}  $intent
     * @param  list<string>  $interactionErrors
     *
     * @throws InvalidAuthTokenException
     */
    public function microsoftError(Request $request, User $approver, array $intent, ?string $code, array $interactionErrors): SymfonyRedirect
    {
        $attempt = (int) ($intent['attempt'] ?? 0);
        $target = User::query()->find($intent['target_id'] ?? null);
        $pending = $this->pendingOrFail($request, $approver, $intent, $target, $attempt);

        if ($attempt !== self::SILENT_ATTEMPT || ! in_array($code, $interactionErrors, true)) {
            return $this->failed($pending, $approver, $target, $code ?: 'microsoft_error', $attempt, null);
        }

        if ($target === null || ! $this->consent->offers($approver, $target)) {
            return $this->failed($pending, $approver, $target, 'target_unavailable', $attempt, null);
        }

        // The retry stays bound to the same pending authorization.
        return $this->toMicrosoft($approver, $target, self::INTERACTIVE_ATTEMPT, $pending['fingerprint']);
    }

    /**
     * The live pending authorization, only when it is the one the intent
     * was started for (same fingerprint, same account). Otherwise (gone,
     * or replaced by another client's or request's consent screen in the
     * same browser) nothing is confirmed, nobody is sent to that other
     * screen, and the person gets Passport's own error.
     *
     * @param  array{binding?: string|null}  $intent
     * @return array{fingerprint: string, client: string, return: string}
     *
     * @throws InvalidAuthTokenException
     */
    private function pendingOrFail(Request $request, User $approver, array $intent, ?User $target, int $attempt): array
    {
        $pending = $this->consent->live($request, $approver);
        $binding = $intent['binding'] ?? null;

        if ($pending === null || ! is_string($binding) || ! hash_equals($pending['fingerprint'], $binding)) {
            $this->audit(self::INCLUDE_FAILED, $approver, $target, $this->failure('authorization_lost', $attempt, $target, null, null));

            throw InvalidAuthTokenException::different();
        }

        return $pending;
    }

    /**
     * @param  array{client: string, return: string}  $pending
     */
    private function failed(array $pending, User $approver, ?User $target, string $reason, int $attempt, ?string $tenantId, string $message = self::FAILED_MESSAGE): RedirectResponse
    {
        $this->audit(self::INCLUDE_FAILED, $approver, $target, $this->failure($reason, $attempt, $target, $pending['client'], $tenantId));

        return redirect($pending['return'])->with('status', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $reason, int $attempt, ?User $target, ?string $client, ?string $tenantId): array
    {
        return [
            'reason' => $reason,
            'attempt' => $attempt,
            'client' => $client,
            'included_user_id' => $target?->getKey(),
            'included_company' => $target?->company?->name,
            'included_email' => $target?->email,
            'tenant_id' => $tenantId === null ? null : strtolower($tenantId),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function audit(string $event, User $approver, ?User $account, array $properties): void
    {
        activity(SecurityLog::LOG)
            ->event($event)
            ->causedBy($approver)
            ->performedOn($account ?? $approver)
            ->withProperties($properties)
            ->log($event);
    }
}
