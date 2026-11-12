<?php

namespace App\Agent;

use App\Management\ManagementPanel;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\Gate;
use JsonException;

/**
 * The short-lived panel token that opens the agent for the account in
 * session (contract §3.2): an HS256 JWT signed with the app's panel key.
 *
 * - `empresa` is the company of the account in session (each company has
 *   its own tenant and account), never `*`;
 * - `sub` is the account's own sign-in name (UPN) in lower case, kept at
 *   every Microsoft sign-in in `users.upn` (see {@see subject()}), and only
 *   when it is at one of the account's company domains: it is an attribute
 *   the tenant's admins control, so a name at someone else's domain is never
 *   signed as an identity. `mail` (`users.email`) is never used; an account
 *   with no UPN yet gets no token until it signs in again;
 * - `rol` is `gerencia` only while the Portal's own management check passes
 *   (the same policy as the management panel, group included), else
 *   `empleado`: a token can only describe a role the Portal gives right now;
 * - it lives {@see LIFETIME} seconds and has a random single-use `jti`.
 *
 * Header: only `alg` and `typ`. Nothing is signed for an account that may
 * not use the agent ({@see eligible()}), and nothing is even built while the
 * key or origin is missing.
 */
final class PanelToken
{
    public const AUDIENCE = 'agente-ryr';

    /** `exp - iat`, the contract's maximum. */
    public const LIFETIME = 300;

    /** Contract §3.2: a token longer than this is refused by the agent. */
    public const MAX_LENGTH = 4096;

    /**
     * The token for the account, or null when it may not have one.
     */
    public static function for(User $user, ?DateTimeInterface $now = null): ?string
    {
        // Configuration first: without a valid key and origin there is nothing
        // to sign, so no claims are built (no random_bytes, no company or
        // management queries).
        $key = AgentSettings::key();

        if ($key === null || AgentSettings::origin() === null) {
            return null;
        }

        $claims = self::claims($user, $now);

        if ($claims === null) {
            return null;
        }

        try {
            $header = self::encode(['alg' => 'HS256', 'typ' => 'JWT']);
            $payload = self::encode($claims);
        } catch (JsonException) {
            return null;
        }

        $signed = $header.'.'.$payload;
        $token = $signed.'.'.self::base64Url(hash_hmac('sha256', $signed, $key, true));

        return strlen($token) <= self::MAX_LENGTH ? $token : null;
    }

    /**
     * The claims in the contract's order, or null when the account is not
     * eligible.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(User $user, ?DateTimeInterface $now = null): ?array
    {
        if (! self::eligible($user)) {
            return null;
        }

        $issuedAt = ($now ?? now())->getTimestamp();

        return [
            'iss' => (string) config('agent.slug'),
            'aud' => self::AUDIENCE,
            'sub' => self::subject($user),
            'empresa' => self::company($user),
            'rol' => self::role($user),
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::LIFETIME,
            'jti' => bin2hex(random_bytes(16)),
        ];
    }

    /**
     * A signed-in Microsoft account of an active company the agent knows,
     * with a UPN at one of that company's domains. Not the emergency
     * accounts, not guests (B2B), not an account without a company, and not
     * a company outside config('agent.companies').
     */
    public static function eligible(User $user): bool
    {
        return ! $user->is_emergency
            && ! $user->is_guest
            && self::subject($user) !== ''
            && self::company($user) !== null
            && $user->company->ownsEmailDomain(self::subject($user));
    }

    /**
     * The company code (SGX, SDC) of the account's company, or null when the
     * company is missing, inactive or not mapped.
     */
    public static function company(User $user): ?string
    {
        $company = $user->company;

        if ($company === null || ! $company->is_active) {
            return null;
        }

        $code = config('agent.companies.'.$company->slug);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * Who the agent sees: the account's UPN in lower case, as the Portal
     * stored it at the last Microsoft sign-in (empty until then, and for a
     * guest UPN). The agent matches it with the address it holds for the
     * person.
     */
    public static function subject(User $user): string
    {
        return mb_strtolower(trim((string) $user->upn));
    }

    public static function role(User $user): string
    {
        return Gate::forUser($user)->allows(ManagementPanel::ABILITY, $user->company)
            ? (string) config('agent.management_agent_role')
            : (string) config('agent.default_role');
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    private static function encode(array $data): string
    {
        return self::base64Url(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
