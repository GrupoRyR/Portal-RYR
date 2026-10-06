<?php

namespace App\Auth;

use PragmaRX\Google2FA\Google2FA;
use Throwable;

/**
 * RFC 6238 TOTP for the emergency account (30-second steps, 6 digits,
 * SHA-1: what every authenticator app supports).
 */
class EmergencyTwoFactor
{
    public const ISSUER = 'Portal RYR';

    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    /**
     * A new base32 secret (160 bits).
     */
    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUri(string $email, #[\SensitiveParameter] string $secret): string
    {
        return $this->google2fa->getQRCodeUrl(self::ISSUER, $email, $secret);
    }

    /**
     * The time step of a valid code newer than `$lastTimestep`, or null.
     *
     * google2fa's `verifyKeyNewer()` returns the matched time step only when
     * an old time step is given (with null it returns plain `true`), so 0
     * stands for "never used". Steps at or before `$lastTimestep` are not
     * searched, which rejects a replayed code.
     */
    public function verify(
        #[\SensitiveParameter] string $secret,
        #[\SensitiveParameter] string $code,
        ?int $lastTimestep,
    ): ?int {
        if (preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }

        try {
            $timestep = $this->google2fa->verifyKeyNewer($secret, $code, $lastTimestep ?? 0);
        } catch (Throwable) {
            return null;
        }

        return is_int($timestep) ? $timestep : null;
    }
}
