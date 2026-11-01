<?php

namespace Tests\Unit;

use App\Support\Geo\Polygon;
use App\Support\Money\Money;
use PHPUnit\Framework\TestCase;

/**
 * WAFFRLI P0 (2026-10-30) — pure helpers (no framework boot).
 */
class PlatformGeometryAndMoneyTest extends TestCase
{
    protected function square(): array
    {
        return ['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]]]];
    }

    public function test_point_in_polygon_including_edges_and_vertices(): void
    {
        $square = $this->square();

        $this->assertTrue(Polygon::contains($square, 5, 5));
        $this->assertTrue(Polygon::contains($square, 0, 5), 'edge counts as inside');
        $this->assertTrue(Polygon::contains($square, 10, 10), 'vertex counts as inside');
        $this->assertFalse(Polygon::contains($square, 10.0001, 5));
        $this->assertFalse(Polygon::contains($square, -1, -1));
    }

    public function test_concave_polygon(): void
    {
        // U shape: the notch (5, 8) is outside.
        $u = ['type' => 'Polygon', 'coordinates' => [[[0, 0], [10, 0], [10, 10], [7, 10], [7, 3], [3, 3], [3, 10], [0, 10], [0, 0]]]];

        $this->assertFalse(Polygon::contains($u, 8, 5));
        $this->assertTrue(Polygon::contains($u, 8, 1));
        $this->assertTrue(Polygon::contains($u, 8, 8.5));
    }

    public function test_polygon_validation_and_bounding_box(): void
    {
        $this->assertNull(Polygon::validationError($this->square()));
        $this->assertNotNull(Polygon::validationError(['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 1], [0, 0]]]]));
        $this->assertNotNull(Polygon::validationError(['type' => 'Polygon', 'coordinates' => [[[0, 0], [200, 0], [1, 1], [0, 0]]]]));
        $this->assertNotNull(Polygon::validationError(['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1], [0, 0]], [[0, 0], [1, 0], [1, 1], [0, 0]]]]));

        $this->assertSame(['min_lat' => 0.0, 'max_lat' => 10.0, 'min_lng' => 0.0, 'max_lng' => 10.0], Polygon::boundingBox($this->square()));
    }

    public function test_money_rounds_half_up_without_floats(): void
    {
        $this->assertSame('0.30', (string) Money::roundTo(Money::of('0.1')->plus('0.2'), 2));
        $this->assertSame('2.6', (string) Money::roundTo('2.55', 1));
        $this->assertSame('-2.6', (string) Money::roundTo('-2.55', 1));
        $this->assertSame('1000.8000', Money::toStorage('1000.8'));
        $this->assertSame('99999999999999.9999', Money::toStorage('99999999999999.9999'));
    }
}
