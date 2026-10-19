<?php

declare(strict_types=1);

namespace CleanPlugins\Scoper\Support;

final class Format
{
    public static function count(int $value): string
    {
        return number_format($value, 0, '.', ',');
    }

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            ++$unit;
        }

        return number_format($value, $unit === 0 ? 0 : 1, '.', '') . ' ' . $units[$unit];
    }

    public static function duration(float $milliseconds): string
    {
        $seconds = $milliseconds / 1000;

        if ($seconds < 60) {
            return number_format($seconds, $seconds < 10 ? 2 : 1, '.', '') . 's';
        }

        return (int) floor($seconds / 60) . 'm ' . str_pad((string) (int) round($seconds % 60), 2, '0', STR_PAD_LEFT) . 's';
    }

    public static function enabled(mixed $value): bool
    {
        return $value !== false && $value !== null && $value !== '' && $value !== '0' && strtolower((string) $value) !== 'false';
    }
}
