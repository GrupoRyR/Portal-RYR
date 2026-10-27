<?php

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurgePublicAvatarsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_deletes_the_public_avatars_and_forgets_their_paths(): void
    {
        $company = Company::factory()->create();
        $withPhoto = User::factory()->microsoft($company)->create(['photo_path' => 'avatars/1.jpg']);
        $withoutPhoto = User::factory()->microsoft($company)->create(['photo_path' => null]);
        Storage::disk('public')->put('avatars/1.jpg', 'jpeg-bytes');
        Storage::disk('public')->put('avatars/2.jpg', 'jpeg-bytes');
        Storage::disk('public')->put('company-logos/segurex.svg', '<svg/>');

        $this->artisan('portal:purge-public-avatars')
            ->expectsOutputToContain('2 fotos borradas, 1 usuario actualizado.')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing('avatars');
        Storage::disk('public')->assertExists('company-logos/segurex.svg');
        $this->assertNull($withPhoto->fresh()->photo_path);
        $this->assertNull($withoutPhoto->fresh()->photo_path);
    }

    public function test_in_production_it_asks_first_and_does_nothing_when_declined(): void
    {
        $this->app['env'] = 'production';
        $user = User::factory()->microsoft(Company::factory()->create())->create(['photo_path' => 'avatars/1.jpg']);
        Storage::disk('public')->put('avatars/1.jpg', 'jpeg-bytes');

        $this->artisan('portal:purge-public-avatars')
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();

        Storage::disk('public')->assertExists('avatars/1.jpg');
        $this->assertSame('avatars/1.jpg', $user->fresh()->photo_path);
    }

    public function test_in_production_force_skips_the_question(): void
    {
        $this->app['env'] = 'production';
        Storage::disk('public')->put('avatars/1.jpg', 'jpeg-bytes');

        $this->artisan('portal:purge-public-avatars', ['--force' => true])
            ->expectsOutputToContain('1 foto borrada')
            ->assertSuccessful();

        Storage::disk('public')->assertMissing('avatars');
    }

    public function test_it_can_run_again_when_there_is_nothing_left(): void
    {
        $this->artisan('portal:purge-public-avatars')
            ->expectsOutputToContain('0 fotos borradas')
            ->assertSuccessful();
    }
}
