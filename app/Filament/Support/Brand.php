<?php

namespace App\Filament\Support;

use Filament\Support\Colors\Color;

/**
 * WAFFRLI admin palette (redesign 2026-11-11). The official logo
 * (public/images/brand, the supplied WAFFRLI | وفرلي artwork) gives the
 * brand green and gold; the panel itself is neutral graphite so that green
 * means "primary action / active item" and gold stays a rare brand detail.
 *
 *  ink      #0D1117  page background in dark mode (gray-950)
 *  surface  #161C24  cards, tables, top bar (gray-900)
 *  primary  #168A63  brand green for actions and the active state
 *                    (white text 4.6:1); 400 #3FB383 on dark surfaces
 *  gold     #C5A15B  muted logo gold: brand lockup details only
 *
 * The same values are mirrored as CSS custom properties (--wf-*) in
 * resources/css/filament/admin/theme.css.
 */
final class Brand
{
    /** Dark page background; also the browser theme colour. */
    public const INK = '#0D1117';

    public const EMERALD = '#168A63';

    public const GOLD = '#C5A15B';

    /** Brand green. @var array<int, string> */
    public const PRIMARY = [
        50 => '#EDF8F3', 100 => '#D3EFE2', 200 => '#A7DFC5', 300 => '#72C9A2', 400 => '#3FB383',
        500 => '#22A875', 600 => '#168A63', 700 => '#127152', 800 => '#105A43', 900 => '#0E4A38', 950 => '#072A20',
    ];

    /** Muted logo gold (brand details only, never a status). @var array<int, string> */
    public const GOLD_PALETTE = [
        50 => '#FAF6EC', 100 => '#F2E9D2', 200 => '#E5D3A8', 300 => '#D6BA7E', 400 => '#C5A15B',
        500 => '#AE8945', 600 => '#8E6F38', 700 => '#71582F', 800 => '#5A4628', 900 => '#4A3A23', 950 => '#2A2013',
    ];

    /**
     * Neutral graphite with a faint cool tint: the surfaces of the whole panel
     * (light mode: 50 page, white cards; dark mode: 950 page, 900 cards).
     *
     * @var array<int, string>
     */
    public const GRAY = [
        50 => '#F6F7F9', 100 => '#ECEEF1', 200 => '#DADEE4', 300 => '#BAC2CC', 400 => '#8F9AA7',
        500 => '#6C7785', 600 => '#4F5967', 700 => '#353E4A', 800 => '#1F2731', 900 => '#161C24', 950 => '#0D1117',
    ];

    /** @return array<string, array<int, string>> Filament panel colors */
    public static function colors(): array
    {
        $oklch = fn (array $palette) => array_map(fn ($hex) => Color::convertToOklch($hex), $palette);

        return [
            'primary' => $oklch(self::PRIMARY),
            'gray' => $oklch(self::GRAY),
            'gold' => $oklch(self::GOLD_PALETTE),
            // Semantic states, distinct from the brand green so that "done"
            // and "primary action" never look alike.
            'success' => Color::Emerald,
            'warning' => Color::Amber,
            'danger' => Color::Red,
            'info' => Color::Blue,
            // Work under way on the road (an order assigned or picked up).
            'transit' => Color::Indigo,
        ];
    }
}
