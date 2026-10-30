<?php

namespace App\Support\Geo;

use InvalidArgumentException;

/**
 * WAFFRLI P0 (2026-10-30) — GeoJSON Polygon helpers for delivery areas.
 *
 * Accepted shape: {"type": "Polygon", "coordinates": [[[lng, lat], ...]]}
 * with exactly one (outer) ring of at least 4 positions whose first and
 * last positions are equal. Coordinates are treated as planar lng/lat,
 * which is accurate enough for city-scale delivery areas. Points exactly
 * on an edge or vertex count as inside.
 */
final class Polygon
{
    /**
     * @return string|null Validation error, or null when valid.
     */
    public static function validationError(mixed $boundary): ?string
    {
        if (! is_array($boundary) || ($boundary['type'] ?? null) !== 'Polygon') {
            return 'The boundary must be a GeoJSON object of type "Polygon".';
        }

        $rings = $boundary['coordinates'] ?? null;
        if (! is_array($rings) || count($rings) !== 1 || ! is_array($rings[0] ?? null)) {
            return 'The boundary must contain exactly one outer ring (holes are not supported).';
        }

        $ring = $rings[0];
        if (count($ring) < 4) {
            return 'The boundary ring must have at least 4 positions (a closed triangle).';
        }

        foreach ($ring as $position) {
            if (! is_array($position) || count($position) !== 2
                || ! is_numeric($position[0] ?? null) || ! is_numeric($position[1] ?? null)) {
                return 'Every boundary position must be a [lng, lat] pair of numbers.';
            }
            if ($position[0] < -180 || $position[0] > 180 || $position[1] < -90 || $position[1] > 90) {
                return 'Boundary positions must be valid longitude/latitude values.';
            }
        }

        if ((float) $ring[0][0] !== (float) end($ring)[0] || (float) $ring[0][1] !== (float) end($ring)[1]) {
            return 'The boundary ring must be closed (first and last positions equal).';
        }

        return null;
    }

    /**
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public static function boundingBox(array $boundary): array
    {
        if ($error = self::validationError($boundary)) {
            throw new InvalidArgumentException($error);
        }

        $lngs = array_map(fn ($p) => (float) $p[0], $boundary['coordinates'][0]);
        $lats = array_map(fn ($p) => (float) $p[1], $boundary['coordinates'][0]);

        return [
            'min_lat' => min($lats),
            'max_lat' => max($lats),
            'min_lng' => min($lngs),
            'max_lng' => max($lngs),
        ];
    }

    public static function contains(array $boundary, float $lat, float $lng): bool
    {
        $ring = $boundary['coordinates'][0] ?? [];
        $count = count($ring);
        if ($count < 4) {
            return false;
        }

        $inside = false;
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            if (self::onSegment($lng, $lat, $xi, $yi, $xj, $yj)) {
                return true;
            }

            if ((($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private static function onSegment(float $px, float $py, float $ax, float $ay, float $bx, float $by): bool
    {
        $cross = ($px - $ax) * ($by - $ay) - ($py - $ay) * ($bx - $ax);
        if (abs($cross) > 1e-12) {
            return false;
        }

        return $px >= min($ax, $bx) - 1e-12 && $px <= max($ax, $bx) + 1e-12
            && $py >= min($ay, $by) - 1e-12 && $py <= max($ay, $by) + 1e-12;
    }
}
