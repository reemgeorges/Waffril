<?php

namespace App\Filament\Support;

use Filament\Support\Colors\Color;

/**
 * WAFFRLI (2026-11-04) — brand tokens sampled from the official logo
 * (public/images/brand, source: the supplied WAFFRLI | وفرلي artwork).
 *
 *  forest   #071B12  page background in dark mode (logo card #01160D–#153725)
 *  emerald  #20A053  logo ribbon; primary actions use darker shades for
 *                    AA contrast with white text (600 #0E7A44 = 5.4:1)
 *  gold     #D9AD59  logo lettering; brand highlights only (never for
 *                    destructive or financial confirmation buttons)
 *  ivory    #F6EDD6  light accents / text on forest
 *
 * The same values are mirrored as CSS custom properties in
 * resources/css/filament/admin/theme.css (--wf-*).
 */
final class Brand
{
    public const FOREST = '#071B12';

    public const EMERALD = '#20A053';

    public const GOLD = '#D9AD59';

    public const IVORY = '#F6EDD6';

    /** @var array<int, string> */
    public const PRIMARY = [
        50 => '#EEF8F1', 100 => '#D3EEDC', 200 => '#A8DDB8', 300 => '#73C68E', 400 => '#3BB463',
        500 => '#157F47', 600 => '#0E7A44', 700 => '#0B6B3D', 800 => '#0A5533', 900 => '#0A452B', 950 => '#052817',
    ];

    /** @var array<int, string> */
    public const GOLD_PALETTE = [
        50 => '#FBF6EA', 100 => '#F6EBCF', 200 => '#EDD6A0', 300 => '#E3BE70', 400 => '#D9AD59',
        500 => '#C9973F', 600 => '#A97A2E', 700 => '#87602A', 800 => '#6E4E27', 900 => '#5B4123', 950 => '#332311',
    ];

    /** Forest-tinted neutrals: warm ivory surfaces in light mode, forest in dark mode. @var array<int, string> */
    public const GRAY = [
        50 => '#F7F5EE', 100 => '#EEEBE1', 200 => '#DCD9CD', 300 => '#C5CABF', 400 => '#9AA59B',
        500 => '#61705F', 600 => '#4B5A50', 700 => '#34443A', 800 => '#1C3127', 900 => '#0F261B', 950 => '#071B12',
    ];

    /** @return array<string, array<int, string>> Filament panel colors */
    public static function colors(): array
    {
        $oklch = fn (array $palette) => array_map(fn ($hex) => Color::convertToOklch($hex), $palette);

        return [
            'primary' => $oklch(self::PRIMARY),
            'gray' => $oklch(self::GRAY),
            'gold' => $oklch(self::GOLD_PALETTE),
            'success' => $oklch(self::PRIMARY),
            'danger' => Color::Red,
            'warning' => Color::Amber,
            'info' => Color::Sky,
        ];
    }
}
