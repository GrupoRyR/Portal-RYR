<?php

namespace Tests\Feature\Mail;

use App\Models\Company;
use App\Models\User;
use App\Notifications\CompanyChangedNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The company notice is painted with the recipient's company (one company
 * per screen), not with the company the notice is about.
 */
class CompanyNoticeBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_mail_uses_the_recipients_company_brand(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/segurex/logo-dark.png', 'png');
        $segurex = Company::factory()->create([
            'name' => 'Segurex Latam',
            'logo_dark_path' => 'brand/segurex/logo-dark.png',
            'color_primary' => '#F26522',
            'color_dark' => '#58595B',
            'color_button' => '#C6531C',
        ]);
        $other = Company::factory()->create(['name' => 'Andina Química', 'color_primary' => '#0F8A7E', 'color_dark' => '#0B3B36']);
        $recipient = User::factory()->microsoft($segurex)->create();

        $html = (string) (new CompanyChangedNotice($other, CompanyChangedNotice::CREATED, 'Ana'))->toMail($recipient)->render();

        $this->assertStringContainsString('alt="Segurex Latam"', $html);
        $this->assertStringContainsString('background-color: #58595B', $html);
        $this->assertStringContainsString('background-color: #C6531C', $html);
        $this->assertStringNotContainsString('#0B3B36', $html);
        $this->assertStringContainsString('Andina Química', $html);
    }

    public function test_a_recipient_without_a_company_gets_the_neutral_mail(): void
    {
        $company = Company::factory()->create(['name' => 'Andina Química', 'color_dark' => '#0B3B36']);
        $recipient = User::factory()->create(['company_id' => null]);

        $html = (string) (new CompanyChangedNotice($company, CompanyChangedNotice::ACTIVATED, null))->toMail($recipient)->render();

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('#0B3B36', $html);
        $this->assertStringContainsString(config('app.name'), $html);
    }
}
