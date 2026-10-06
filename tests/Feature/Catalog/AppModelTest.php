<?php

namespace Tests\Feature\Catalog;

use App\Models\App;
use App\Rules\AllowedAppUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class AppModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_slug_cannot_change_after_creation(): void
    {
        $app = App::factory()->create(['slug' => 'cartera']);

        $this->expectException(LogicException::class);

        $app->update(['slug' => 'cartera-nueva']);
    }

    public function test_the_slug_stays_in_the_database_when_the_change_is_rejected(): void
    {
        $app = App::factory()->create(['slug' => 'cartera']);

        try {
            $app->update(['slug' => 'otra', 'name' => 'Otro nombre']);
        } catch (LogicException) {
        }

        $this->assertDatabaseHas('apps', ['id' => $app->id, 'slug' => 'cartera', 'name' => $app->getOriginal('name')]);
    }

    public function test_other_attributes_can_change(): void
    {
        $app = App::factory()->create(['slug' => 'cartera']);

        $app->update(['name' => 'Cartera nueva', 'url' => 'https://cartera.segurex.com.co']);

        $this->assertSame('cartera', $app->fresh()->slug);
        $this->assertSame('Cartera nueva', $app->fresh()->name);
    }

    public function test_a_web_app_cannot_be_saved_with_a_url_outside_the_allowed_hosts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        App::factory()->create(['url' => 'https://segurex.com.co.evil.io']);
    }

    public function test_a_web_app_needs_a_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        App::factory()->create(['url' => null]);
    }

    public function test_a_local_app_has_no_url(): void
    {
        $this->expectException(InvalidArgumentException::class);

        App::factory()->local()->create(['url' => 'https://segurex.com.co']);
    }

    public function test_a_local_app_saves_without_a_url(): void
    {
        $app = App::factory()->local()->create();

        $this->assertNull($app->fresh()->url);
        $this->assertTrue($app->isLocal());
    }

    public function test_the_allowed_hosts_come_from_configuration(): void
    {
        $this->assertSame(
            ['segurex.com.co', 'derivadoscol.com.co', 'sgx-*.vercel.app'],
            config('portal.allowed_app_hosts'),
        );

        config(['portal.allowed_app_hosts' => ['example.org']]);

        $this->assertTrue(App::urlIsAllowed('https://example.org/app'));
        $this->assertFalse(App::urlIsAllowed('https://segurex.com.co'));
    }

    public function test_the_validation_rule_reports_a_spanish_message(): void
    {
        $validator = Validator::make(
            ['url' => 'http://pedidos.segurex.com.co'],
            ['url' => [new AllowedAppUrl]],
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('https://', $validator->errors()->first('url'));

        $this->assertTrue(Validator::make(
            ['url' => 'https://sgx-cartera.vercel.app/login'],
            ['url' => [new AllowedAppUrl]],
        )->passes());
    }
}
