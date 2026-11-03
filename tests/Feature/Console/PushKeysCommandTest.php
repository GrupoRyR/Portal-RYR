<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Push\PushTestKeys;
use Tests\TestCase;

/**
 * `php artisan portal:push-keys` prints a fresh VAPID key pair for the
 * .env of the server (docs/despliegue.md §1.22). It writes nothing.
 */
class PushKeysCommandTest extends TestCase
{
    use PushTestKeys;

    public function test_it_prints_a_fresh_key_pair_for_the_env_file(): void
    {
        $this->requireEcKeys();

        $this->assertSame(0, Artisan::call('portal:push-keys'));
        $first = Artisan::output();

        // \r?: lines end with PHP_EOL, \r\n on Windows.
        $this->assertMatchesRegularExpression('/^PUSH_VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}\r?$/m', $first);
        $this->assertMatchesRegularExpression('/^PUSH_VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}\r?$/m', $first);
        $this->assertMatchesRegularExpression('/^PUSH_VAPID_SUBJECT=mailto:\S+@\S+\r?$/m', $first);

        Artisan::call('portal:push-keys');
        preg_match('/^PUSH_VAPID_PRIVATE_KEY=([A-Za-z0-9_-]+)/m', $first, $a);
        preg_match('/^PUSH_VAPID_PRIVATE_KEY=([A-Za-z0-9_-]+)/m', Artisan::output(), $b);

        $this->assertNotSame($a[1], $b[1]);
    }
}
