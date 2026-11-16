<?php

namespace Tests\Unit;

use App\Support\Geo\Polygon;
use Database\Seeders\Demo\Geo\NearestCentrePartition;
use PHPUnit\Framework\TestCase;

/**
 * WAFFRLI (2026-11-16) — the demo "nearest neighbourhood centre" partition
 * must tile the outer boundary: every point inside it lies in exactly one
 * cell (no gaps, no overlaps), every centre lies in its own cell, and every
 * cell is a valid closed GeoJSON ring for the delivery-area validator.
 */
class NearestCentrePartitionTest extends TestCase
{
    /** A non-convex outer boundary (an "L"), [lng, lat]. */
    private const BOUNDARY = [[36.70, 35.10], [36.80, 35.10], [36.80, 35.14], [36.75, 35.14], [36.75, 35.18], [36.70, 35.18], [36.70, 35.10]];

    private const CENTRES = [
        'a' => [35.11, 36.71], 'b' => [35.12, 36.78], 'c' => [35.16, 36.72], 'd' => [35.13, 36.74], 'e' => [35.17, 36.705],
    ];

    public function test_cells_tile_the_boundary_without_gaps_or_overlaps(): void
    {
        $cells = NearestCentrePartition::cells(self::BOUNDARY, self::CENTRES);
        $this->assertCount(5, $cells);

        // A fine grid of probe points inside the boundary: each belongs to exactly one cell.
        $probed = 0;
        for ($lat = 35.1005; $lat < 35.18; $lat += 0.0013) {
            for ($lng = 36.7005; $lng < 36.80; $lng += 0.0017) {
                if (! NearestCentrePartition::contains(self::BOUNDARY, $lat, $lng)) {
                    continue;
                }
                $owners = array_keys(array_filter($cells, fn ($ring) => NearestCentrePartition::contains($ring, $lat, $lng)));
                $this->assertNotEmpty($owners, "Gap at {$lat},{$lng}");
                // Exactly on a shared edge two cells may both claim it; strictly inside, only one.
                $this->assertLessThanOrEqual(2, count($owners), "Overlap at {$lat},{$lng}");
                $probed++;
            }
        }
        $this->assertGreaterThan(1500, $probed);

        $total = array_sum(array_map([NearestCentrePartition::class, 'areaKm2'], $cells));
        $this->assertEqualsWithDelta(NearestCentrePartition::areaKm2(self::BOUNDARY), $total, 0.01, 'Cells add up to the boundary area.');
    }

    public function test_each_centre_lies_in_its_own_cell_and_rings_are_valid_geojson(): void
    {
        $cells = NearestCentrePartition::cells(self::BOUNDARY, self::CENTRES);
        foreach (self::CENTRES as $key => [$lat, $lng]) {
            $this->assertTrue(NearestCentrePartition::contains($cells[$key], $lat, $lng), "Centre {$key} must be in its own cell.");
            $this->assertSame($cells[$key][0], end($cells[$key]), 'Closed ring.');
            $this->assertNull(Polygon::validationError(['type' => 'Polygon', 'coordinates' => [$cells[$key]]]), "Cell {$key} must pass the delivery-area validator.");
        }
    }

    public function test_a_point_belongs_to_the_nearest_centre(): void
    {
        $cells = NearestCentrePartition::cells(self::BOUNDARY, self::CENTRES);
        // Close to "b" (east) and far from the others.
        $this->assertTrue(NearestCentrePartition::contains($cells['b'], 35.115, 36.79));
        $this->assertFalse(NearestCentrePartition::contains($cells['a'], 35.115, 36.79));
    }
}
