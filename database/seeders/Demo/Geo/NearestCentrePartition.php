<?php

namespace Database\Seeders\Demo\Geo;

use InvalidArgumentException;

/**
 * WAFFRLI demo data (2026-11-16) — splits a VERIFIED outer boundary (e.g. a
 * city polygon from OpenStreetMap) into "nearest centre" cells around
 * VERIFIED named points (e.g. OSM neighbourhood nodes): every location
 * inside the boundary belongs to the cell of the closest centre.
 *
 * This is a documented APPROXIMATION, not an official neighbourhood
 * boundary. It is used when real neighbourhood polygons are not mapped:
 * the cells tile the whole boundary (no gaps, no overlaps apart from
 * shared edges), so every valid address resolves to a named area.
 *
 * Distances use a local equirectangular projection (longitude scaled by
 * cos(latitude)), which is accurate to well under 1 % across a city.
 * Each cell is the boundary clipped by the half-planes "closer to this
 * centre than to that one" (Sutherland–Hodgman), so a cell is a single
 * ring whenever the boundary is.
 */
final class NearestCentrePartition
{
    /**
     * @param  list<array{0: float, 1: float}>  $boundary  closed or open ring of [lng, lat]
     * @param  array<string, array{0: float, 1: float}>  $centres  key => [lat, lng]
     * @return array<string, list<array{0: float, 1: float}>> key => closed ring of [lng, lat]
     */
    public static function cells(array $boundary, array $centres, int $precision = 6): array
    {
        $ring = self::open($boundary);
        if (count($ring) < 3) {
            throw new InvalidArgumentException('The boundary needs at least three distinct positions.');
        }
        $scale = cos(deg2rad(array_sum(array_column($ring, 1)) / count($ring)));
        $project = fn (array $p) => [$p[0] * $scale, $p[1]];
        $unproject = fn (array $p) => [round($p[0] / $scale, $precision), round($p[1], $precision)];

        $subject = array_map($project, $ring);
        $sites = [];
        foreach ($centres as $key => [$lat, $lng]) {
            $sites[$key] = [$lng * $scale, $lat];
        }

        $cells = [];
        foreach ($sites as $key => $site) {
            $cell = $subject;
            foreach ($sites as $otherKey => $other) {
                if ($otherKey === $key || $cell === []) {
                    continue;
                }
                // Keep points p with |p - site|² <= |p - other|²  ⇔  a·p <= b
                $a = [2 * ($other[0] - $site[0]), 2 * ($other[1] - $site[1])];
                $b = ($other[0] ** 2 + $other[1] ** 2) - ($site[0] ** 2 + $site[1] ** 2);
                $cell = self::clip($cell, $a, $b);
            }
            $cell = self::dedupe(array_map($unproject, $cell));
            if (count($cell) >= 3) {
                $cell[] = $cell[0];
                $cells[$key] = $cell;
            }
        }

        return $cells;
    }

    /** Point-in-ring test ([lng, lat] ring), boundary points count as inside. */
    public static function contains(array $ring, float $lat, float $lng): bool
    {
        $ring = self::open($ring);
        $inside = false;
        $count = count($ring);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /** Approximate area in km² of a [lng, lat] ring. */
    public static function areaKm2(array $ring): float
    {
        $ring = self::open($ring);
        $lat0 = deg2rad(array_sum(array_column($ring, 1)) / count($ring));
        $r = 6371.0;
        $sum = 0.0;
        $n = count($ring);
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $ring[$i];
            [$x2, $y2] = $ring[($i + 1) % $n];
            $sum += deg2rad($x1) * $r * cos($lat0) * deg2rad($y2) * $r - deg2rad($x2) * $r * cos($lat0) * deg2rad($y1) * $r;
        }

        return abs($sum) / 2;
    }

    /** @param list<array{0: float, 1: float}> $polygon */
    private static function clip(array $polygon, array $a, float $b): array
    {
        $inside = fn (array $p) => $b + 1e-12 >= $a[0] * $p[0] + $a[1] * $p[1];
        $intersect = function (array $p, array $q) use ($a, $b): array {
            $dp = $a[0] * $p[0] + $a[1] * $p[1] - $b;
            $dq = $a[0] * $q[0] + $a[1] * $q[1] - $b;
            $t = $dp / ($dp - $dq);

            return [$p[0] + $t * ($q[0] - $p[0]), $p[1] + $t * ($q[1] - $p[1])];
        };

        $out = [];
        $n = count($polygon);
        for ($i = 0; $i < $n; $i++) {
            $current = $polygon[$i];
            $previous = $polygon[($i + $n - 1) % $n];
            if ($inside($current)) {
                if (! $inside($previous)) {
                    $out[] = $intersect($previous, $current);
                }
                $out[] = $current;
            } elseif ($inside($previous)) {
                $out[] = $intersect($previous, $current);
            }
        }

        return $out;
    }

    private static function open(array $ring): array
    {
        $ring = array_values(array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $ring));
        if (count($ring) > 1 && $ring[0] === $ring[count($ring) - 1]) {
            array_pop($ring);
        }

        return $ring;
    }

    private static function dedupe(array $ring): array
    {
        $out = [];
        foreach ($ring as $p) {
            if ($out === [] || $out[count($out) - 1] !== $p) {
                $out[] = $p;
            }
        }
        if (count($out) > 1 && $out[0] === $out[count($out) - 1]) {
            array_pop($out);
        }

        return $out;
    }
}
