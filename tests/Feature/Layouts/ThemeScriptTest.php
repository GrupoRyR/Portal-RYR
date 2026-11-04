<?php

namespace Tests\Feature\Layouts;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The header scripts (resources/js/theme.js and resources/js/header.js):
 * their own tests in tests/js run under Node's built-in runner, so they
 * need no package. Skipped where Node is not installed.
 */
class ThemeScriptTest extends TestCase
{
    public function test_the_header_scripts_pass_their_node_tests(): void
    {
        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('Node is not installed.');
        }

        $result = Process::path(base_path())->run(['node', '--test', 'tests/js/*.test.mjs']);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
        $this->assertMatchesRegularExpression('/^ℹ pass [1-9]\d*$/mu', $result->output());
    }
}
