<?php

declare(strict_types=1);

namespace App\Format;

/**
 * Renders a number with the decimal separator of the requested locale, so a
 * French alert message never shows "43.9". Grouping is disabled: the alert
 * engine only formats small magnitudes, and a group separator would be a
 * narrow no-break space in French.
 */
final class DecimalFormatter
{
    public function format(float $value, string $locale, int $minFractionDigits = 0, int $maxFractionDigits = 1): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $formatter->setAttribute(\NumberFormatter::ROUNDING_MODE, \NumberFormatter::ROUND_HALFUP);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minFractionDigits);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits);
        $formatter->setAttribute(\NumberFormatter::GROUPING_USED, 0);

        $formatted = $formatter->format($value);
        \assert(\is_string($formatted));

        return $formatted;
    }
}
