<?php
declare(strict_types=1);
namespace App\Support;

/** Fixed-point signed sums and proportional invoice carrying value; never floats. */
final class FinancialMath
{
    public static function sum(string ...$values): string
    {
        $positive = $negative = '0.0000';
        foreach ($values as $value) {
            if (str_starts_with($value, '-')) { $negative = Decimal::add($negative, substr($value,1),4); }
            else { $positive = Decimal::add($positive,$value,4); }
        }
        return Decimal::signedSubtract($positive,$negative,4);
    }
    public static function negate(string $value): string { return str_starts_with($value,'-') ? substr($value,1) : '-' . $value; }
    public static function min(string $a, string $b): string { return Decimal::compare($a,$b,4) <= 0 ? $a : $b; }
    public static function carrying(array $payable, string $original): string
    {
        return Decimal::divide(Decimal::multiplyRounded($payable['original_khr'],$original,8,40),$payable['amount_original'],4);
    }
}
