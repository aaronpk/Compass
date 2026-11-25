<?php
namespace Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use App\Stats\Geo\Geo;

class GeoTest extends TestCase {

  public function testDistanceBetweenIdenticalPointsIsZero() {
    $this->assertSame(0.0, Geo::distance(45.5, -122.6, 45.5, -122.6));
  }

  public function testDistanceMatchesKnownValues() {
    // One degree of latitude is about 111.2 km
    $this->assertEqualsWithDelta(111195, Geo::distance(0, 0, 1, 0), 10);
    // Portland to Seattle
    $this->assertEqualsWithDelta(233000, Geo::distance(45.5152, -122.6784, 47.6062, -122.3321), 2000);
    // Across the antimeridian: 0.2 degrees of longitude at the equator
    $this->assertEqualsWithDelta(22239, Geo::distance(0, 179.9, 0, -179.9), 10);
  }

  public function testGeohashMatchesReferenceValues() {
    // Reference values from the original geohash.org description
    $this->assertSame('ezs42', Geo::geohash(42.6, -5.6, 5));
    $this->assertSame('u4pruydqqvj', Geo::geohash(57.64911, 10.40744, 11));
  }

  public function testGeohashCenterIsInsideItsCell() {
    foreach([[45.5152, -122.6784], [-33.8688, 151.2093], [0.0, 0.0], [89.9, 179.9]] as [$lat, $lng]) {
      foreach([4, 7] as $precision) {
        $hash = Geo::geohash($lat, $lng, $precision);
        [$centerLat, $centerLng] = Geo::geohashCenter($hash);
        $this->assertSame($hash, Geo::geohash($centerLat, $centerLng, $precision));
      }
    }
  }

}
