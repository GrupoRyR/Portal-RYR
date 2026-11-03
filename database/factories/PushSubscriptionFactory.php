<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A Chrome subscription with a valid P-256 browser key.
 *
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.Str::random(40);

        return [
            'user_id' => User::factory(),
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'public_key' => 'BEbKigHHaOl-5V3a8zsjEJZrJfKuxs0ikK5Gh5PItSEHA1J_WtET39dEqtn5I_n8S0s-FCYR_zXbAiBPAsKdn60',
            'auth_token' => 'Zm9vYmFyMTIzNDU2Nzg5MA',
            'content_encoding' => 'aes128gcm',
            'user_agent' => 'Mozilla/5.0 (Linux; Android 14)',
        ];
    }

    public function endpoint(string $endpoint): static
    {
        return $this->state(fn () => ['endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashOf($endpoint)]);
    }
}
