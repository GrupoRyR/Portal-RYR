<?php

namespace Tests\Unit\Markets;

use App\Markets\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Exact decimal products for the euro in pesos, without floats and
 * without needing the bcmath extension.
 */
class DecimalTest extends TestCase
{
    public function test_it_multiplies_exactly(): void
    {
        $this->assertSame('3437.6292', Decimal::multiply('3182.9900', '1.0800', 4));
        $this->assertSame('3520.0000', Decimal::multiply('3200.0000', '1.1000', 4));
        $this->assertSame('6.2500', Decimal::multiply('2.5', '2.5', 4));
        $this->assertSame('0.0000', Decimal::multiply('0', '1.08', 4));
        $this->assertSame('1.0000', Decimal::multiply('1', '1', 4));
    }

    public function test_it_rounds_half_up_to_the_scale(): void
    {
        $this->assertSame('1.0001', Decimal::multiply('1.00005', '1', 4));
        $this->assertSame('1.0000', Decimal::multiply('1.00004', '1', 4));
        $this->assertSame('1.0000', Decimal::multiply('1.000049999', '1', 4));
        $this->assertSame('10.0000', Decimal::multiply('9.99995', '1', 4));
        $this->assertSame('0.0001', Decimal::multiply('0.00005', '1', 4));
        $this->assertSame('0.0000', Decimal::multiply('0.00004', '1', 4));
    }

    public function test_it_keeps_the_precision_floats_lose(): void
    {
        // 12 digits times 11 digits: far beyond a float's 15-17 digits and an int's range.
        $this->assertSame('9999999999.9999', Decimal::multiply('9999999999.9999', '1.00000000', 4));
        $this->assertSame('999999999899.9900', Decimal::multiply('9999999999.9999', '99.99999999', 4));
    }

    public function test_it_agrees_with_bcmath_on_many_products(): void
    {
        if (! extension_loaded('bcmath')) {
            $this->markTestSkipped('bcmath is not installed here.');
        }

        mt_srand(20261103);

        for ($i = 0; $i < 300; $i++) {
            $a = mt_rand(1, 9999999).'.'.str_pad((string) mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $b = mt_rand(0, 9).'.'.str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);

            // Exact product, then half-up: add half a unit of the last place and cut.
            $expected = bcadd(bcmul($a, $b, 12), '0.00005', 4);

            $this->assertSame($expected, Decimal::multiply($a, $b, 4), "{$a} x {$b}");
        }
    }

    public function test_it_rejects_what_is_not_a_plain_non_negative_decimal(): void
    {
        foreach (['', 'abc', '-1', '1e3', '1,5', '.5', '1.', ' 1', '1 '] as $bad) {
            try {
                Decimal::multiply($bad, '1', 4);
                $this->fail("Accepted [{$bad}].");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
