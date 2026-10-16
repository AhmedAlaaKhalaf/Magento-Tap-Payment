<?php
declare(strict_types=1);

namespace Tap\Payment\Model;

/**
 * Currency precision as Tap expects it (ISO 4217 minor units).
 */
class Amount
{
    private const THREE_DECIMALS = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];
    private const ZERO_DECIMALS = ['JPY', 'KRW', 'VND'];

    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);
        if (in_array($currency, self::THREE_DECIMALS, true)) {
            return 3;
        }
        return in_array($currency, self::ZERO_DECIMALS, true) ? 0 : 2;
    }

    public static function round(float $amount, string $currency): float
    {
        return round($amount, self::decimals($currency));
    }

    /** Fixed-precision string, e.g. "1.000" for KWD; used for comparisons and webhook signatures. */
    public static function format(float|string $amount, string $currency): string
    {
        return number_format((float) $amount, self::decimals($currency), '.', '');
    }

    public static function equals(float|string $a, float|string $b, string $currency): bool
    {
        return self::format($a, $currency) === self::format($b, $currency);
    }
}
