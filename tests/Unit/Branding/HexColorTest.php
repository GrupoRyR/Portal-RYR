<?php

namespace Tests\Unit\Branding;

use App\Branding\HexColor;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HexColorTest extends TestCase
{
    public function test_it_normalizes_valid_colors_to_uppercase(): void
    {
        $color = HexColor::from('#ff671f');

        $this->assertSame('#FF671F', $color->toHex());
        $this->assertSame([255, 103, 31], [$color->red, $color->green, $color->blue]);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidValues(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'short form' => ['#fff'],
            'missing hash' => ['FF671F'],
            'css injection' => ['#fff;}body{'],
            'injection after a valid color' => ['#FF671F;}body{background:red}'],
            'trailing newline' => ["#FF671F\n"],
            'eight digits' => ['#FF671F80'],
            'named color' => ['orange'],
            'function' => ['rgb(255,0,0)'],
            'non hex digits' => ['#GG671F'],
            'not a string' => [123456],
        ];
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_invalid_values(mixed $value): void
    {
        $this->assertNull(HexColor::tryFrom($value));
    }

    public function test_from_throws_on_invalid_values(): void
    {
        $this->expectException(InvalidArgumentException::class);

        HexColor::from('#fff;}body{');
    }
}
