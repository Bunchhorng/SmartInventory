<?php
declare(strict_types=1);

namespace App\Support;

use DomainException;

/**
 * Small fixed-point helper for values that must never pass through PHP floats.
 * Database columns remain the source of truth; this class only validates and
 * formats request values and conversion previews.
 */
final class Decimal
{
    public static function nonNegative(string $value, int $scale, int $maxDigits = 18): string
    {
        $normalized = self::normalize($value, $scale, $maxDigits);
        return $normalized;
    }

    public static function positive(string $value, int $scale, int $maxDigits = 18): string
    {
        $normalized = self::normalize($value, $scale, $maxDigits);
        if (self::isZero($normalized)) {
            throw new DomainException('The value must be greater than zero.');
        }

        return $normalized;
    }

    /** Validates a fixed-point value while retaining an optional minus sign. */
    public static function signed(string $value, int $scale, int $maxDigits = 18): string
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-');
        $normalized = self::normalize($negative ? substr($value, 1) : $value, $scale, $maxDigits);
        return $negative && !self::isZero($normalized) ? '-' . $normalized : $normalized;
    }

    public static function isZeroValue(string $value): bool
    {
        return self::isZero($value);
    }

    /**
     * Multiplies two validated decimal strings and returns a fixed-scale result.
     * Excess non-zero fractional digits are rejected rather than silently rounded.
     */
    public static function multiply(string $left, string $right, int $resultScale): string
    {
        [$leftDigits, $leftScale] = self::digitsAndScale($left);
        [$rightDigits, $rightScale] = self::digitsAndScale($right);
        $digits = self::multiplyDigits($leftDigits, $rightDigits);
        $scale = $leftScale + $rightScale;

        if ($scale > $resultScale) {
            $drop = $scale - $resultScale;
            $discarded = substr($digits, -$drop);
            if (trim($discarded, '0') !== '') {
                throw new DomainException('The converted quantity exceeds the supported decimal precision.');
            }
            $digits = substr($digits, 0, -$drop);
            $scale = $resultScale;
        }

        if ($scale < $resultScale) {
            $digits .= str_repeat('0', $resultScale - $scale);
            $scale = $resultScale;
        }

        return self::formatDigits($digits, $scale);
    }

    public static function compare(string $left, string $right, int $scale): int
    {
        $left = self::nonNegative($left, $scale);
        $right = self::nonNegative($right, $scale);
        $leftDigits = str_replace('.', '', $left);
        $rightDigits = str_replace('.', '', $right);

        return strlen($leftDigits) <=> strlen($rightDigits)
            ?: strcmp($leftDigits, $rightDigits);
    }

    /**
     * Adds two non-negative fixed-point values without using PHP floats.
     */
    public static function add(string $left, string $right, int $scale, int $maxDigits = 18): string
    {
        $left = self::nonNegative($left, $scale, $maxDigits);
        $right = self::nonNegative($right, $scale, $maxDigits);
        $digits = self::addDigits(
            str_replace('.', '', $left),
            str_replace('.', '', $right),
        );

        return self::nonNegative(self::formatDigits($digits, $scale), $scale, $maxDigits);
    }

    /**
     * Subtracts two non-negative fixed-point values and rejects a negative result.
     */
    public static function subtract(string $left, string $right, int $scale, int $maxDigits = 18): string
    {
        $left = self::nonNegative($left, $scale, $maxDigits);
        $right = self::nonNegative($right, $scale, $maxDigits);
        if (self::compare($left, $right, $scale) < 0) {
            throw new DomainException('The calculation would produce a negative value.');
        }

        return self::nonNegative(
            self::formatDigits(self::subtractDigits(str_replace('.', '', $left), str_replace('.', '', $right)), $scale),
            $scale,
            $maxDigits,
        );
    }

    /**
     * Subtracts two non-negative values and preserves a negative result.
     * This is used for reporting values such as gross profit, not for stock
     * or valuation state, which must remain non-negative.
     */
    public static function signedSubtract(string $left, string $right, int $scale, int $maxDigits = 18): string
    {
        $left = self::nonNegative($left, $scale, $maxDigits);
        $right = self::nonNegative($right, $scale, $maxDigits);

        if (self::compare($left, $right, $scale) >= 0) {
            return self::subtract($left, $right, $scale, $maxDigits);
        }

        return '-' . self::subtract($right, $left, $scale, $maxDigits);
    }

    /**
     * Multiplies two non-negative decimal values and rounds half up to the
     * requested result scale. Inventory value is stored at four KHR decimals.
     */
    public static function multiplyRounded(string $left, string $right, int $resultScale, int $maxDigits = 18): string
    {
        [$leftDigits, $leftScale] = self::digitsAndScale($left);
        [$rightDigits, $rightScale] = self::digitsAndScale($right);
        $digits = self::multiplyDigits($leftDigits, $rightDigits);
        $scale = $leftScale + $rightScale;

        if ($scale > $resultScale) {
            $drop = $scale - $resultScale;
            $kept = substr($digits, 0, -$drop);
            $discarded = substr($digits, -$drop);
            $digits = $kept === '' ? '0' : $kept;
            if ((int) $discarded[0] >= 5) {
                $digits = self::addDigits($digits, '1');
            }
            $scale = $resultScale;
        }

        if ($scale < $resultScale) {
            $digits .= str_repeat('0', $resultScale - $scale);
        }

        return self::nonNegative(self::formatDigits($digits, $resultScale), $resultScale, $maxDigits);
    }

    /**
     * Divides two non-negative fixed-point values with half-up rounding and
     * no dependency on binary floating point or the bcmath extension.
     */
    public static function divide(string $dividend, string $divisor, int $resultScale, int $maxDigits = 18): string
    {
        [$dividendDigits, $dividendScale] = self::digitsAndScale($dividend);
        [$divisorDigits, $divisorScale] = self::digitsAndScale($divisor);
        if ($divisorDigits === '0') {
            throw new DomainException('A decimal value cannot be divided by zero.');
        }

        $numerator = $dividendDigits . str_repeat('0', $divisorScale + $resultScale);
        $denominator = $divisorDigits . str_repeat('0', $dividendScale);
        [$quotient, $remainder] = self::divideDigits($numerator, $denominator);
        if (self::compareDigits(self::multiplySingleDigit($remainder, 2), $denominator) >= 0) {
            $quotient = self::addDigits($quotient, '1');
        }

        return self::nonNegative(self::formatDigits($quotient, $resultScale), $resultScale, $maxDigits);
    }

    private static function normalize(string $value, int $scale, int $maxDigits): string
    {
        $value = trim($value);
        if ($scale < 0 || $maxDigits < 1 || !preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new DomainException('A non-negative decimal value is required.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        if (strlen($whole) + strlen($fraction) > $maxDigits || strlen($fraction) > $scale) {
            throw new DomainException(sprintf('The value may contain at most %d digits and %d decimal places.', $maxDigits, $scale));
        }

        return $whole . ($scale > 0 ? '.' . str_pad($fraction, $scale, '0') : '');
    }

    private static function isZero(string $value): bool
    {
        return trim(str_replace('.', '', $value), '0') === '';
    }

    /** @return array{0: string, 1: int} */
    private static function digitsAndScale(string $value): array
    {
        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new DomainException('A decimal value is required.');
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        return [ltrim($whole . $fraction, '0') ?: '0', strlen($fraction)];
    }

    private static function multiplyDigits(string $left, string $right): string
    {
        if ($left === '0' || $right === '0') {
            return '0';
        }

        $result = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; $i--) {
            $leftDigit = (int) $left[$i];
            for ($j = strlen($right) - 1; $j >= 0; $j--) {
                $position = $i + $j + 1;
                $result[$position] += $leftDigit * (int) $right[$j];
            }
        }
        for ($i = count($result) - 1; $i > 0; $i--) {
            $result[$i - 1] += intdiv($result[$i], 10);
            $result[$i] %= 10;
        }

        return ltrim(implode('', $result), '0') ?: '0';
    }

    private static function addDigits(string $left, string $right): string
    {
        $carry = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry > 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += (int) $left[$leftIndex--];
            }
            if ($rightIndex >= 0) {
                $sum += (int) $right[$rightIndex--];
            }
            $result = (string) ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function subtractDigits(string $left, string $right): string
    {
        $borrow = 0;
        $result = '';
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        while ($leftIndex >= 0) {
            $digit = (int) $left[$leftIndex--] - $borrow;
            $rightDigit = $rightIndex >= 0 ? (int) $right[$rightIndex--] : 0;
            if ($digit < $rightDigit) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = (string) ($digit - $rightDigit) . $result;
        }

        return ltrim($result, '0') ?: '0';
    }

    private static function compareDigits(string $left, string $right): int
    {
        $left = ltrim($left, '0') ?: '0';
        $right = ltrim($right, '0') ?: '0';
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }

    private static function multiplySingleDigit(string $value, int $digit): string
    {
        if ($digit < 0 || $digit > 9) {
            throw new DomainException('Unsupported decimal calculation.');
        }
        return self::multiplyDigits($value, (string) $digit);
    }

    /** @return array{0: string, 1: string} */
    private static function divideDigits(string $numerator, string $denominator): array
    {
        $numerator = ltrim($numerator, '0') ?: '0';
        $denominator = ltrim($denominator, '0') ?: '0';
        $remainder = '0';
        $quotient = '';
        foreach (str_split($numerator) as $digit) {
            $remainder = ltrim($remainder . $digit, '0') ?: '0';
            $quotientDigit = 0;
            for ($candidate = 9; $candidate > 0; $candidate--) {
                if (self::compareDigits(self::multiplySingleDigit($denominator, $candidate), $remainder) <= 0) {
                    $quotientDigit = $candidate;
                    break;
                }
            }
            if ($quotientDigit > 0) {
                $remainder = self::subtractDigits($remainder, self::multiplySingleDigit($denominator, $quotientDigit));
            }
            $quotient .= (string) $quotientDigit;
        }

        return [ltrim($quotient, '0') ?: '0', $remainder];
    }

    private static function formatDigits(string $digits, int $scale): string
    {
        $digits = ltrim($digits, '0') ?: '0';
        if ($scale === 0) {
            return $digits;
        }
        if (strlen($digits) <= $scale) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
        }

        return substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }
}
