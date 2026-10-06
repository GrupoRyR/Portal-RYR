<?php

namespace Tests\Unit\Support;

use App\Support\DeviceClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DeviceClassifierTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string}>
     */
    public static function userAgents(): array
    {
        return [
            'windows chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36 Edg/129.0', 'desktop'],
            'mac safari' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Safari/605.1.15', 'desktop'],
            'linux firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:131.0) Gecko/20100101 Firefox/131.0', 'desktop'],
            'iphone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'phone'],
            'android phone' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36', 'phone'],
            'teams mobile' => ['Mozilla/5.0 (Linux; Android 13; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Mobile Safari/537.36 Teams/1416/1.0.0', 'phone'],
            'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1', 'tablet'],
            'android tablet' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36', 'tablet'],
            'kindle' => ['Mozilla/5.0 (Linux; Android 9; KFTRWI) AppleWebKit/537.36 (KHTML, like Gecko) Silk/128.0 like Chrome/128.0 Safari/537.36', 'tablet'],
            'empty' => ['', 'desktop'],
            'missing' => [null, 'desktop'],
        ];
    }

    #[DataProvider('userAgents')]
    public function test_it_classifies_the_device_from_the_user_agent(?string $userAgent, string $expected): void
    {
        $this->assertSame($expected, DeviceClassifier::classify($userAgent));
    }
}
