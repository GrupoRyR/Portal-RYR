<?php

namespace Tests\Feature\Layouts;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The theme choice of the header (resources/js/theme.js): its own tests in
 * tests/js run under Node's built-in runner, so they need no package.
 * Skipped where Node is not installed.
 */
class ThemeScriptTest extends TestCase
{
    public function test_the_theme_script_passes_its_node_tests(): void
    {
        if (! Process::run('node --version')->successful()) {
            $this->markTestSkipped('Node is not installed.');
        }

        $result = Process::path(base_path())->run(['node', '--test', 'tests/js/theme.test.mjs']);

        $this->assertTrue($result->successful(), $result->output().$result->errorOutput());
    }
}
