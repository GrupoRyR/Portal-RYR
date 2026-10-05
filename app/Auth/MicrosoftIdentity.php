<?php

namespace App\Auth;

use Laravel\Socialite\Contracts\User as SocialiteUser;
use stdClass;

/**
 * Who signed in with Microsoft, taken from the validated id_token claims
 * (`tid`, `oid`) and the Graph profile Socialite fetched.
 */
final readonly class MicrosoftIdentity
{
    public function __construct(
        public string $tenantId,
        public string $objectId,
        public ?string $email,
        public string $name,
        public string $accessToken,
    ) {}

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
            throw LoginFailed::retry();
        }

        $email = self::email([
            $raw['mail'] ?? null,
            $claims->email ?? null,
            $raw['userPrincipalName'] ?? null,
            $claims->preferred_username ?? null,
            $claims->upn ?? null,
        ]);

        $name = trim((string) ($user->getName() ?? $claims->name ?? ''));

        return new self($tenantId, $objectId, $email, $name !== '' ? $name : (string) $email, $accessToken);
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

    private static function guid(mixed $value): string
    {
        return strtolower(trim((string) $value));
    }
}
