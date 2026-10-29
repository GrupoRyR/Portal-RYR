<?php

namespace App\Auth;

use Laravel\Socialite\Contracts\User as SocialiteUser;
use stdClass;

/**
 * Who signed in with Microsoft, taken from the validated id_token claims
 * (`tid`, `oid`, `idp`, `auth_time`, `login_hint`) and the Graph profile
 * Socialite fetched.
 *
 * `email` comes from attributes the signing tenant's admins control, so it
 * is only a hint: it proves nothing outside that tenant.
 */
final readonly class MicrosoftIdentity
{
    public function __construct(
        public string $tenantId,
        public string $objectId,
        public ?string $email,
        public string $name,
        public string $accessToken,
        public ?string $homeTenantId = null,
        public ?int $authTime = null,
        public ?string $loginHint = null,
    ) {}

    /**
     * Whether the account lives in the tenant it signed in to (not a guest).
     */
    public function isHomeSignIn(): bool
    {
        return $this->homeTenantId === $this->tenantId;
    }

    /**
     * @param  stdClass|null  $claims  The provider's validated id_token claims.
     *
     * @throws LoginFailed when the token lacks the tenant or object ID.
     */
    public static function fromSocialite(SocialiteUser $user, ?stdClass $claims): self
    {
        $raw = method_exists($user, 'getRaw') ? $user->getRaw() : [];

        $tenantId = self::guid($claims->tid ?? null);
        $objectId = self::guid($claims->oid ?? null);
        $accessToken = (string) ($user->token ?? '');

        if ($tenantId === '' || $objectId === '' || $accessToken === '') {
            throw LoginFailed::retry('missing_claims');
        }

        $email = self::email([
            $raw['mail'] ?? null,
            $claims->email ?? null,
            $raw['userPrincipalName'] ?? null,
            $claims->preferred_username ?? null,
            $claims->upn ?? null,
        ]);

        $name = trim((string) ($user->getName() ?? $claims->name ?? ''));

        return new self(
            $tenantId,
            $objectId,
            $email,
            $name !== '' ? $name : (string) $email,
            $accessToken,
            self::homeTenant($claims->idp ?? null, $tenantId),
            self::timestamp($claims->auth_time ?? null),
            self::loginHint([$claims->login_hint ?? null, $claims->preferred_username ?? null, $email]),
        );
    }

    /**
     * The tenant the account is homed in. Per the id_token reference, `idp`
     * equals the issuer unless the account is not in the issuer's tenant
     * (guests), where it holds the home tenant's STS URI, e.g.
     * `https://sts.windows.net/{homeTid}/`; when absent, `iss` (the signing
     * tenant) applies. An `idp` that names no Entra tenant (e.g. `live.com`)
     * yields null: no tenant is proven.
     *
     * @see https://learn.microsoft.com/en-us/entra/identity-platform/id-token-claims-reference
     */
    private static function homeTenant(mixed $idp, string $tenantId): ?string
    {
        if ($idp === null || trim((string) $idp) === '') {
            return $tenantId;
        }

        $pattern = '#^https://(?:sts\.windows\.net|login\.microsoftonline\.com)/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:/|$)#i';

        return preg_match($pattern, trim((string) $idp), $matches) === 1 ? strtolower($matches[1]) : null;
    }

    /**
     * The first usable address: the `mail` attribute, else the UPN. A guest
     * UPN (`…#EXT#@tenant.onmicrosoft.com`) is never an email address.
     *
     * @param  list<mixed>  $candidates
     */
    private static function email(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim((string) $candidate));

            if (str_contains($candidate, '@') && ! str_contains($candidate, '#ext#')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * What to send Microsoft as `login_hint` for this account: the id_token
     * `login_hint` claim (an opaque hint Microsoft issues for exactly this),
     * else the sign-in name, else the email. A value too long for the column
     * is skipped, not cut: a cut hint would point to no account.
     *
     * @param  list<mixed>  $candidates
     */
    private static function loginHint(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';

            if ($candidate !== '' && strlen($candidate) <= 255 && preg_match('/[\x00-\x1F\x7F]/', $candidate) !== 1) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * `auth_time` (optional claim, Unix seconds): when the user last entered
     * their credentials. Null when absent or not a number.
     */
    private static function timestamp(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private static function guid(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
