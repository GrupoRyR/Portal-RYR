<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pages around the sign-in (emergency access, admin consent and the
 * access errors) are simple glass cards in the Vitrina's tone: the glass
 * page background, one card, and the error inside it.
 */
class GlassAuthPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function pages(): array
    {
        return [
            'emergency sign-in' => ['auth.emergency-login', []],
            'emergency code' => ['auth.emergency-code', []],
            'admin consent granted' => ['auth.admin-consent', ['granted' => true]],
            'admin consent missing' => ['auth.admin-consent', ['granted' => false]],
            'forbidden' => ['errors.403', []],
            'microsoft required' => ['errors.microsoft-required', []],
            'reauthentication unavailable' => ['errors.reauth-unavailable', []],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    #[DataProvider('pages')]
    public function test_the_page_is_one_glass_card_on_the_glass_background(string $view, array $data): void
    {
        $html = (string) $this->withViewErrors([])->view($view, $data);

        $this->assertMatchesRegularExpression('/<body class="[^"]*\bg-canvas\b/', $html);
        $this->assertSame(1, substr_count($html, 'class="g-card '));
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertDoesNotMatchRegularExpression('/grupo/i', $html);
    }

    public function test_the_emergency_fields_are_glass_inputs_and_the_error_sits_on_the_card(): void
    {
        $html = (string) $this->withViewErrors(['emergency' => 'Datos incorrectos.'])->view('auth.emergency-login');

        $this->assertSame(2, substr_count($html, 'class="g-input '));
        $this->assertMatchesRegularExpression('/<p role="alert" class="[^"]*text-\(--g-crit\)[^"]*">Datos incorrectos\.<\/p>/', $html);
        $this->assertStringContainsString('g-btn g-btn--ink', $html);
    }
}
