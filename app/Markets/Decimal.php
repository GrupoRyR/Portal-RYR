<?php

namespace App\Markets;

use InvalidArgumentException;

/**
 * Exact arithmetic on plain decimal strings (`3182.9900`), for the euro in
 * pesos: floats drift in the last digits and the bcmath extension is not
 * guaranteed on the shared host, so the digits are multiplied by hand.
 */
final class Decimal
{
    private const PLAIN = '/\A\d+(\.\d+)?\z/';

    /**
     * The exact product of two non-negative decimals, rounded half up to
     * `$scale` decimals.
     *
     * @throws InvalidArgumentException when an operand is not a plain non-negative decimal
     */
    public static function multiply(string $a, string $b, int $scale): string
    {
        [$aDigits, $aScale] = self::split($a);
        [$bDigits, $bScale] = self::split($b);

        $product = self::multiplyDigits($aDigits, $bDigits);
        $productScale = $aScale + $bScale;

        if ($productScale > $scale) {
            $dropped = $productScale - $scale;
            $padded = str_pad($product, $dropped + 1, '0', STR_PAD_LEFT);
            $kept = substr($padded, 0, -$dropped);

            // Half up: the first dropped digit decides.
            $product = $padded[strlen($padded) - $dropped] >= '5' ? self::increment($kept) : $kept;
        } else {
            $product .= str_repeat('0', $scale - $productScale);
        }

        $product = ltrim($product, '0');

        if ($scale === 0) {
            return $product === '' ? '0' : $product;
        }

        $product = str_pad($product, $scale + 1, '0', STR_PAD_LEFT);

        return substr($product, 0, -$scale).'.'.substr($product, -$scale);
    }

    /**
     * @return array{0: string, 1: int} The digits without the point, and how many are decimals.
     */
    private static function split(string $number): array
    {
        if (preg_match(self::PLAIN, $number) !== 1) {
            throw new InvalidArgumentException('Not a plain non-negative decimal.');
        }

        $parts = explode('.', $number);

        return [$parts[0].($parts[1] ?? ''), strlen($parts[1] ?? '')];
    }

    /**
     * Schoolbook multiplication of two digit strings.
     */
    private static function multiplyDigits(string $a, string $b): string
    {
        $x = array_reverse(array_map('intval', str_split($a)));
        $y = array_reverse(array_map('intval', str_split($b)));
        $cells = array_fill(0, count($x) + count($y), 0);

        foreach ($x as $i => $xi) {
            foreach ($y as $j => $yj) {
                $cells[$i + $j] += $xi * $yj;
            }
        }

        $carry = 0;

        foreach ($cells as $k => $cell) {
            $total = $cell + $carry;
            $cells[$k] = $total % 10;
            $carry = intdiv($total, 10);
        }

        return ltrim(implode('', array_reverse($cells)), '0') ?: '0';
    }

    /**
     * One more unit of the last digit of a digit string.
     */
    private static function increment(string $digits): string
    {
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            if ($digits[$i] !== '9') {
                $digits[$i] = (string) ((int) $digits[$i] + 1);

                return $digits;
            }

            $digits[$i] = '0';
        }

        return '1'.$digits;
    }
}
