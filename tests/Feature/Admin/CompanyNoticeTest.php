<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use App\Notifications\CompanyAdminNotifier;
use App\Notifications\CompanyChangedNotice;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

class CompanyNoticeTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $superAdmin;

    private User $portalAdmin;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['portal.super_admin_emails' => ['jhon@segurex.com', 'Super@Segurex.com']]);

        $this->company = Company::factory()->create(['name' => 'Andina S.A.S.', 'slug' => 'andina', 'tenant_id' => '54f53029-e6e2-4b5f-a862-3454ae886468']);
        $this->superAdmin = User::factory()->create(['name' => 'Jhon Castro', 'email' => 'jhon@segurex.com']);
        $this->superAdmin->assignRole('super_admin');
        $this->portalAdmin = User::factory()->create(['email' => 'admin@derivados.com']);
        $this->portalAdmin->assignRole('portal_admin');
        $this->employee = User::factory()->create();
    }

    public function test_admins_and_configured_super_admins_are_notified_once(): void
    {
        Notification::fake();

        app(CompanyAdminNotifier::class)->notify($this->company, CompanyChangedNotice::CREATED, $this->superAdmin);

        Notification::assertSentTo([$this->superAdmin, $this->portalAdmin], CompanyChangedNotice::class);
        Notification::assertNotSentTo($this->employee, CompanyChangedNotice::class);
        Notification::assertSentOnDemand(
            CompanyChangedNotice::class,
            fn ($notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'super@segurex.com',
        );
        // jhon@segurex.com is a user already: one notice, not two.
        Notification::assertSentOnDemandTimes(CompanyChangedNotice::class, 1);
    }

    public function test_notices_wait_for_the_transaction_to_commit(): void
    {
        Notification::fake();

        DB::transaction(function () {
            app(CompanyAdminNotifier::class)->notify($this->company, CompanyChangedNotice::ACTIVATED, $this->superAdmin);
            Notification::assertNothingSent();
        });

        Notification::assertSentTo($this->portalAdmin, CompanyChangedNotice::class);
    }

    public function test_a_mail_failure_is_logged_and_does_not_break_the_action(): void
    {
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings) {
            if ($event->level === 'warning') {
                $warnings[] = $event->message;
            }
        });
        Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('SMTP down'));
        Notification::shouldReceive('route')->andReturn(new AnonymousNotifiable);

        app(CompanyAdminNotifier::class)->notify($this->company, CompanyChangedNotice::TENANT_CHANGED, $this->superAdmin);

        $this->assertContains('Company notice could not be sent.', $warnings);
    }

    public function test_the_mail_is_in_spanish_and_names_the_company(): void
    {
        foreach ([
            CompanyChangedNotice::CREATED => 'Nueva empresa en el portal: Andina S.A.S.',
            CompanyChangedNotice::TENANT_CHANGED => 'Cambió el inquilino de Andina S.A.S.',
            CompanyChangedNotice::ACTIVATED => 'Andina S.A.S. ya puede entrar al portal',
        ] as $event => $subject) {
            $mail = (new CompanyChangedNotice($this->company, $event, 'Jhon Castro'))->toMail($this->portalAdmin);

            $this->assertSame($subject, $mail->subject);
            $rendered = (string) $mail->render();
            $this->assertStringContainsString('54f53029-e6e2-4b5f-a862-3454ae886468', $rendered);
            $this->assertStringContainsString('Jhon Castro', $rendered);
            $this->assertStringContainsString(route('admin.companies.show', 'andina'), $rendered);
        }
    }
}
