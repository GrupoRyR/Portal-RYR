<?php

namespace Tests\Feature\Branding;

use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The sign-in page stays neutral (no company is known yet) but follows the
 * brand manual's type rules: sentence case, no sustained uppercase.
 */
class SignInBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sign_in_page_uses_sentence_case(): void
    {
        Company::factory()->create(['name' => 'Segurex Latam']);

        $this->get(route('login'))
            ->assertOk()
            ->assertSeeText('Grupo Empresarial RYR')
            ->assertDontSee('uppercase', false);
    }
}
