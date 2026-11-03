<?php

namespace Tests\Unit\Mcp;

use App\Mcp\ClaudeRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClaudeRedirectTest extends TestCase
{
    public function test_only_the_exact_claude_callback_is_allowed(): void
    {
        $this->assertTrue(ClaudeRedirect::allows('https://claude.ai/api/mcp/auth_callback'));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function others(): array
    {
        return [
            'trailing slash' => ['https://claude.ai/api/mcp/auth_callback/'],
            'upper case host' => ['https://CLAUDE.AI/api/mcp/auth_callback'],
            'lookalike host' => ['https://claude.ai.evil.com/api/mcp/auth_callback'],
            'explicit port' => ['https://claude.ai:443/api/mcp/auth_callback'],
            'query' => ['https://claude.ai/api/mcp/auth_callback?x=1'],
            'empty' => [''],
            'not a string' => [['https://claude.ai/api/mcp/auth_callback']],
        ];
    }

    #[DataProvider('others')]
    public function test_anything_else_is_not(mixed $uri): void
    {
        $this->assertFalse(ClaudeRedirect::allows($uri));
    }
}
