<?php

namespace Tests\Feature\Agent;

use Tests\TestCase;

/**
 * The Portal's own copy of the agent's launcher (connection contract v0.4,
 * §3.6). It runs inside the Portal's pages, so updating it is always a
 * decision: if the file changes, these tests fail until the expected version
 * and hash (taken from the contract's table) are updated together.
 */
class LauncherCopyTest extends TestCase
{
    private const EXPECTED_VERSION = '2';

    private const EXPECTED_SHA256 = 'a480f3a71d8d2d7b17f75e612237a54876b34022d8f01eeb7bdb1c3634835eeb';

    private function copy(): string
    {
        return (string) file_get_contents(public_path('js/agente-lanzador.js'));
    }

    public function test_the_copy_is_exactly_the_version_in_the_contract(): void
    {
        $this->assertSame(
            self::EXPECTED_SHA256,
            hash('sha256', $this->copy()),
            'public/js/agente-lanzador.js changed: compare it with the contract\'s SHA-256 table and update EXPECTED_VERSION and EXPECTED_SHA256 on purpose.',
        );
    }

    public function test_the_declared_version_is_the_expected_one(): void
    {
        $this->assertMatchesRegularExpression("/LANZADOR_VERSION = '".self::EXPECTED_VERSION."'/", $this->copy());
    }

    public function test_it_takes_the_origin_from_the_attribute_and_reads_the_token_from_the_fragment(): void
    {
        $this->assertStringContainsString('data-agente-origen', $this->copy());
        $this->assertStringContainsString('#t=', $this->copy());
    }
}
