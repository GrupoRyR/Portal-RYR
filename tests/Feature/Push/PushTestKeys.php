<?php

namespace Tests\Feature\Push;

/**
 * VAPID settings and browser keys for the push tests. The VAPID pair is a
 * throwaway one made for these tests (`php artisan portal:push-keys`); the
 * browser key is a valid P-256 public key, so the real library can
 * encrypt for it.
 */
trait PushTestKeys
{
    public const VAPID_PUBLIC = 'BAR4lDmOnYUfWgI7sEvl5uGJUiAIt4nc3Fb-B1YfouPapliagMZxLPHLto4Bm5s8tx9n8LQ2EkiIklGRzVfVTXE';

    public const VAPID_PRIVATE = '6xuHr2U2ZVXVyXkjnXeTRa_pA1lnMmmpEHWmL7owU_c';

    public const P256DH = 'BEbKigHHaOl-5V3a8zsjEJZrJfKuxs0ikK5Gh5PItSEHA1J_WtET39dEqtn5I_n8S0s-FCYR_zXbAiBPAsKdn60';

    public const AUTH = 'Zm9vYmFyMTIzNDU2Nzg5MA';

    /**
     * The real library makes P-256 keys with OpenSSL. PHP on Windows needs
     * OPENSSL_CONF pointing at an openssl.cnf for that; Linux (the server)
     * does not. Without it, the tests that use the real library are skipped.
     */
    private function requireEcKeys(): void
    {
        $key = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        while (openssl_error_string() !== false) {
            // Drain OpenSSL's error queue so later calls start clean.
        }

        if ($key === false) {
            $this->markTestSkipped('OpenSSL cannot make P-256 keys here (on Windows, set OPENSSL_CONF to an openssl.cnf).');
        }
    }

    private function configurePush(): void
    {
        config([
            'push.vapid.public_key' => self::VAPID_PUBLIC,
            'push.vapid.private_key' => self::VAPID_PRIVATE,
            'push.vapid.subject' => 'mailto:soporte@segurexlatam.net',
        ]);
    }
}
