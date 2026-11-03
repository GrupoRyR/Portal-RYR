<?php

namespace App\Work;

/**
 * The signature of the portal's calls to the tasks app
 * (docs/contrato-tareas-aprobaciones.md §6.2): `sha256=` and the lowercase
 * hex HMAC-SHA256, keyed with the whole secret as shown (prefix included),
 * of `timestamp`, `method`, `path` and the raw body joined by "\n". The
 * path is signed exactly as it is sent, still percent-encoded.
 *
 * The secret is `ryrsig_` and 43 characters: 32 random bytes in base64url
 * without padding. It is shown once and stored encrypted
 * (App\Models\Integration::issueSigningSecret()).
 */
final class PortalSignature
{
    public const SECRET_PREFIX = 'ryrsig_';

    public const PREFIX = 'sha256=';

    public static function sign(string $secret, string $timestamp, string $method, string $path, string $body): string
    {
        return self::PREFIX.hash_hmac('sha256', $timestamp."\n".strtoupper($method)."\n".$path."\n".$body, $secret);
    }

    public static function newSecret(): string
    {
        return self::SECRET_PREFIX.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
