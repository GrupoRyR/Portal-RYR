<?php

namespace Tests\Unit\Work;

use App\Work\PortalSignature;
use PHPUnit\Framework\TestCase;

/**
 * The signature of «Completar» (docs/contrato-tareas-aprobaciones.md
 * §6.2), checked against the contract's own test data, which the tasks app
 * uses in its tests too.
 */
class PortalSignatureTest extends TestCase
{
    private const SECRET = 'ryrsig_EJEMPLO_no_usar_en_produccion_0123456789';

    private const PATH = '/api/portal/v1/tasks/8812%3A0b7d5e3a-0000-4000-8000-000000000002/complete';

    private const BODY = '{"assignee":{"tenant_id":"6f1c2a9e-0000-4000-8000-000000000001","oid":"0b7d5e3a-0000-4000-8000-000000000002"},"completed_at":"2026-10-29T14:00:00-05:00","request_id":"9a1f3c6e-2b4d-4e8f-9c0a-7d5b3e1f2a6c"}';

    public function test_it_matches_the_test_data_of_the_contract(): void
    {
        $this->assertSame(
            'sha256=2cedea4d9a6e9ded7595db836c02ea855879bc48276263574a9a6ddd7b8d50f0',
            PortalSignature::sign(self::SECRET, '1793300400', 'POST', self::PATH, self::BODY),
        );
    }

    public function test_the_method_path_and_body_are_all_signed(): void
    {
        $signature = PortalSignature::sign(self::SECRET, '1793300400', 'POST', self::PATH, self::BODY);

        $this->assertSame($signature, PortalSignature::sign(self::SECRET, '1793300400', 'post', self::PATH, self::BODY));
        $this->assertNotSame($signature, PortalSignature::sign(self::SECRET, '1793300401', 'POST', self::PATH, self::BODY));
        $this->assertNotSame($signature, PortalSignature::sign(self::SECRET, '1793300400', 'PUT', self::PATH, self::BODY));
        $this->assertNotSame($signature, PortalSignature::sign(self::SECRET, '1793300400', 'POST', str_replace('8812', '8813', self::PATH), self::BODY));
        $this->assertNotSame($signature, PortalSignature::sign(self::SECRET, '1793300400', 'POST', self::PATH, self::BODY.' '));
        $this->assertNotSame($signature, PortalSignature::sign(self::SECRET.'x', '1793300400', 'POST', self::PATH, self::BODY));
    }

    public function test_a_new_secret_has_the_prefix_and_43_characters_of_32_random_bytes(): void
    {
        $secret = PortalSignature::newSecret();

        $this->assertMatchesRegularExpression('/\Aryrsig_[A-Za-z0-9_-]{43}\z/', $secret);
        $this->assertSame(32, strlen(base64_decode(strtr(substr($secret, 7), '-_', '+/'), true)));
        $this->assertNotSame($secret, PortalSignature::newSecret());
    }
}
