<?php
namespace Tests\Unit\Geofence;

use App\Geofence\Shapes;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ShapesTest extends TestCase {

  const LAT = 45.5;
  const LNG = -122.6;

  // Degrees for a number of metres north and east of the test spot
  public static function at(float $north, float $east): array {
    return [self::LAT + $north / 111195, self::LNG + $east / (111195 * cos(deg2rad(self::LAT)))];
  }

  // A square of the given half-width around the test spot, optionally with a square hole
  public static function square(float $half, ?float $hole = null): array {
    $ring = function(float $h) {
      $corners = [[-$h, -$h], [-$h, $h], [$h, $h], [$h, -$h], [-$h, -$h]];
      return array_map(function($c) { [$lat, $lng] = ShapesTest::at($c[0], $c[1]); return [$lng, $lat]; }, $corners);
    };
    $rings = [$ring($half)];
    if($hole)
      $rings[] = $ring($hole);
    $parsed = Shapes::parseGeoJSON(json_encode(['type' => 'Polygon', 'coordinates' => $rings]));
    return ['kind' => 'polygon', 'polygons' => $parsed['polygons'], 'bbox' => $parsed['bbox']];
  }

  public function testCircleDistanceIsNegativeInsideAndPositiveOutside() {
    $circle = ['kind' => 'circle', 'lat' => self::LAT, 'lng' => self::LNG, 'radius' => 150];
    $this->assertEqualsWithDelta(-150, Shapes::distance($circle, self::LAT, self::LNG), 0.01);
    $this->assertEqualsWithDelta(50, Shapes::distance($circle, ...self::at(200, 0)), 0.5);
    $this->assertEqualsWithDelta(-50, Shapes::distance($circle, ...self::at(0, 100)), 0.5);
  }

  public function testPolygonDistanceIsMeasuredToTheNearestEdge() {
    $square = self::square(100);
    $this->assertEqualsWithDelta(-100, Shapes::distance($square, self::LAT, self::LNG), 0.5);
    $this->assertEqualsWithDelta(-20, Shapes::distance($square, ...self::at(0, 80)), 0.5);
    $this->assertEqualsWithDelta(50, Shapes::distance($square, ...self::at(0, 150)), 0.5);
    // Diagonally off a corner: the distance to the corner itself
    $this->assertEqualsWithDelta(hypot(30, 40), Shapes::distance($square, ...self::at(130, 140)), 0.5);
  }

  public function testAHoleInAPolygonIsOutside() {
    $ring = self::square(200, 50);
    $this->assertEqualsWithDelta(50, Shapes::distance($ring, self::LAT, self::LNG), 0.5);
    $this->assertEqualsWithDelta(-50, Shapes::distance($ring, ...self::at(0, 100)), 0.5);
    $this->assertGreaterThan(0, Shapes::distance($ring, ...self::at(0, 300)));
  }

  public function testAPointFarFromAPolygonIsJustFar() {
    $this->assertGreaterThan(Shapes::FAR, Shapes::distance(self::square(100), ...self::at(50000, 0)));
  }

  public function testSizeOfACircleAndAPolygon() {
    $this->assertSame(150.0, Shapes::size(['kind' => 'circle', 'lat' => 0, 'lng' => 0, 'radius' => 150]));
    $this->assertEqualsWithDelta(100, Shapes::size(self::square(100)), 0.5);
  }

  public function testALineThroughAFencePassesThroughIt() {
    $circle = ['kind' => 'circle', 'lat' => self::LAT, 'lng' => self::LNG, 'radius' => 150];
    foreach([$circle, self::square(150)] as $fence) {
      $this->assertTrue(Shapes::passesThrough($fence, ...[...self::at(0, -1000), ...self::at(0, 1000)]));
      // Half a kilometre to the north of it
      $this->assertFalse(Shapes::passesThrough($fence, ...[...self::at(500, -1000), ...self::at(500, 1000)]));
      // Clipping the edge isn't passing 50 m inside
      $this->assertFalse(Shapes::passesThrough($fence, ...[...self::at(140, -1000), ...self::at(140, 1000), 50]));
      $this->assertTrue(Shapes::passesThrough($fence, ...[...self::at(60, -1000), ...self::at(60, 1000), 50]));
    }
  }

  public function testGeoJSONIsAcceptedBareOrWrapped() {
    $geometry = ['type' => 'Polygon', 'coordinates' => [[[-122.61, 45.5], [-122.6, 45.5], [-122.6, 45.51]]]];
    foreach([$geometry, ['type' => 'Feature', 'properties' => [], 'geometry' => $geometry], ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'geometry' => $geometry]]]] as $input) {
      $parsed = Shapes::parseGeoJSON(json_encode($input));
      $this->assertSame('Polygon', $parsed['geometry']['type']);
      // The ring is closed for storage
      $this->assertCount(4, $parsed['polygons'][0][0]);
      $this->assertSame([45.5, 45.51, -122.61, -122.6], $parsed['bbox']);
    }
  }

  public function testSeveralPolygonsBecomeAMultiPolygon() {
    $a = ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[0, 0], [1, 0], [1, 1]]]]];
    $b = ['type' => 'Feature', 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[5, 5], [6, 5], [6, 6]]]]];
    $parsed = Shapes::parseGeoJSON(json_encode(['type' => 'FeatureCollection', 'features' => [$a, $b]]));
    $this->assertSame('MultiPolygon', $parsed['geometry']['type']);
    $this->assertCount(2, $parsed['polygons']);
  }

  public static function badGeoJSON(): array {
    return [
      'not json' => ['{nope', 'not valid JSON'],
      'a point' => ['{"type":"Point","coordinates":[0,0]}', 'Polygon or MultiPolygon'],
      'two corners' => ['{"type":"Polygon","coordinates":[[[0,0],[1,1]]]}', 'three corners'],
      'latitude first' => ['{"type":"Polygon","coordinates":[[[45,-122],[45,-123],[46,-123]]]}', 'out of range'],
      'across the date line' => ['{"type":"Polygon","coordinates":[[[179,0],[-179,0],[-179,1]]]}', '180'],
      'not numbers' => ['{"type":"Polygon","coordinates":[[["a","b"],[1,1],[2,2]]]}', 'longitude, latitude'],
    ];
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('badGeoJSON')]
  public function testBadGeoJSONIsRefusedWithAReason(string $text, string $reason) {
    try {
      Shapes::parseGeoJSON($text);
      $this->fail('accepted');
    } catch(InvalidArgumentException $e) {
      $this->assertStringContainsString($reason, $e->getMessage());
    }
  }

  public function testAShapeWithTooManyPointsIsRefused() {
    $ring = [];
    for($i = 0; $i <= Shapes::MAX_VERTICES; $i++)
      $ring[] = [cos($i / 800) , sin($i / 800)];
    $this->expectException(InvalidArgumentException::class);
    Shapes::parseGeoJSON(json_encode(['type' => 'Polygon', 'coordinates' => [$ring]]));
  }

  public function testFenceFromADatabaseRow() {
    $circle = Shapes::fromRow((object)['kind' => 'circle', 'lat' => '45.50000', 'lng' => '-122.60000', 'radius' => 150]);
    $this->assertSame(['kind' => 'circle', 'lat' => 45.5, 'lng' => -122.6, 'radius' => 150.0], $circle);
    $this->assertNull(Shapes::fromRow((object)['kind' => 'place']));
    $this->assertNull(Shapes::fromRow((object)['kind' => 'polygon', 'geojson' => 'broken']));
  }

}
