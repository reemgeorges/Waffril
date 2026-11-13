<?php

namespace App\Filament\Support;

/**
 * Placeholder pictures for lists (table UX overhaul, 2026-11-12). A store
 * without a logo or a product without a picture shows its initial on a calm
 * brand tint instead of an empty cell or a broken image. The colour is
 * derived from the name, so the same record always gets the same tint.
 * Display only: nothing is stored.
 */
final class Avatar
{
    /** Background / text pairs from the WAFFRLI palette (greens, gold, teal, slate). */
    private const TONES = [
        ['#E7F2EC', '#0F5A3A'],
        ['#FBF1DC', '#8A5A00'],
        ['#E3F1F2', '#145E66'],
        ['#EEF0F4', '#3B4558'],
        ['#F3E9E4', '#7A3E22'],
        ['#E9EEF8', '#2D4A86'],
    ];

    /** An SVG data URI with the first letter of $name. */
    public static function placeholder(?string $name): string
    {
        $name = trim((string) $name);
        // Arabic names often start with the article "ال": use the first
        // letter after it, so "السوق الطازج" shows "س", not "ا".
        $base = preg_replace('/^ال(?=\X{2})/u', '', $name);
        $letter = $name === '' ? '•' : mb_strtoupper(mb_substr($base, 0, 1));
        [$background, $foreground] = self::TONES[abs(crc32($name)) % count(self::TONES)];

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="14" fill="'.$background.'"/>'
            .'<text x="32" y="33" dominant-baseline="middle" text-anchor="middle" '
            .'font-family="IBM Plex Sans Arabic, Inter, system-ui, sans-serif" font-size="28" font-weight="600" fill="'.$foreground.'">'
            .htmlspecialchars($letter, ENT_QUOTES | ENT_XML1).'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
