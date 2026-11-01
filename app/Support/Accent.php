<?php

namespace App\Support;

final class Accent
{
    public static function palette(?string $hex): array
    {
        $hex = preg_match('/^#[0-9a-f]{6}$/i', (string) $hex) ? strtolower($hex) : '#004f9f';
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        $rgb = [$r, $g, $b];

        return [
            'hex'        => $hex,
            'rgb'        => "$r $g $b",
            'fg'         => self::ratio($rgb, [255, 255, 255]) >= 4.5 ? '#ffffff' : '#18181b',
            'text_light' => self::tune($rgb, [255, 255, 255], [0, 0, 0]),
            'text_dark'  => self::tune($rgb, [24, 24, 27], [255, 255, 255]),
        ];
    }

    private static function tune(array $c, array $bg, array $toward): string
    {
        for ($t = 0; $t <= 1.0001; $t += 0.05) {
            $m = array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $t), $c, $toward);
            if (self::ratio($m, $bg) >= 4.5) {
                return sprintf('#%02x%02x%02x', ...$m);
            }
        }

        return sprintf('#%02x%02x%02x', ...$toward);
    }

    private static function lum(array $c): float
    {
        [$r, $g, $b] = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $c);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private static function ratio(array $a, array $b): float
    {
        [$l1, $l2] = [max(self::lum($a), self::lum($b)), min(self::lum($a), self::lum($b))];

        return ($l1 + 0.05) / ($l2 + 0.05);
    }
}
