<?php

namespace Tests\Unit;

use App\Support\Accent;
use PHPUnit\Framework\TestCase;

class AccentTest extends TestCase
{
    private function hexToRgb(string $hex): array
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return [$r, $g, $b];
    }

    private function luminance(array $c): float
    {
        [$r, $g, $b] = array_map(function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $c);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    private function contrastRatio(string $hexA, array $rgbB): float
    {
        $rgbA = $this->hexToRgb($hexA);
        $l1 = max($this->luminance($rgbA), $this->luminance($rgbB));
        $l2 = min($this->luminance($rgbA), $this->luminance($rgbB));

        return ($l1 + 0.05) / ($l2 + 0.05);
    }

    public function test_normal_novotel_hex_produces_expected_rgb_and_wcag_aa_contrast(): void
    {
        $palette = Accent::palette('#004F9F');

        $this->assertSame('#004f9f', $palette['hex']);
        $this->assertSame('0 79 159', $palette['rgb']);
        $this->assertSame('#ffffff', $palette['fg']);
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($palette['text_light'], [255, 255, 255]));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($palette['text_dark'], [24, 24, 27]));
    }

    public function test_bright_amber_hex_is_auto_tuned_to_pass_wcag_aa_contrast(): void
    {
        $palette = Accent::palette('#f59e0b');

        $this->assertSame('#f59e0b', $palette['hex']);
        $this->assertSame('245 158 11', $palette['rgb']);
        $this->assertSame('#18181b', $palette['fg']);
        $this->assertNotSame('#f59e0b', $palette['text_light']);
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($palette['text_light'], [255, 255, 255]));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($palette['text_dark'], [24, 24, 27]));
    }

    public function test_dark_teal_and_crimson_red_hex_are_calculated_accurately(): void
    {
        $teal = Accent::palette('#0f766e');
        $this->assertSame('#0f766e', $teal['hex']);
        $this->assertSame('15 118 110', $teal['rgb']);
        $this->assertSame('#ffffff', $teal['fg']);
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($teal['text_light'], [255, 255, 255]));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($teal['text_dark'], [24, 24, 27]));

        $crimson = Accent::palette('#be123c');
        $this->assertSame('#be123c', $crimson['hex']);
        $this->assertSame('190 18 60', $crimson['rgb']);
        $this->assertSame('#ffffff', $crimson['fg']);
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($crimson['text_light'], [255, 255, 255]));
        $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($crimson['text_dark'], [24, 24, 27]));
    }

    public function test_null_or_invalid_hex_safely_falls_back_to_default_novotel_blue(): void
    {
        foreach ([null, '', 'invalid', '#12345', '#zzzzzz', '004f9f'] as $invalidInput) {
            $palette = Accent::palette($invalidInput);

            $this->assertSame('#004f9f', $palette['hex']);
            $this->assertSame('0 79 159', $palette['rgb']);
            $this->assertSame('#ffffff', $palette['fg']);
            $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($palette['text_light'], [255, 255, 255]));
            $this->assertGreaterThanOrEqual(4.5, $this->contrastRatio($palette['text_dark'], [24, 24, 27]));
        }
    }
}
