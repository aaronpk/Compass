<?php
namespace Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use App\Stats\Geo\HexGrid;

class HexGridTest extends TestCase {

  public function testTheCentreOfAHexagonIsInThatHexagon() {
    foreach([[0, 0], [237, 66], [359, -46], [180, 13], [5, 180], [200, -90]] as [$column, $row]) {
      [$lat, $lng] = HexGrid::center($column, $row);
      $this->assertSame([$column, $row], HexGrid::cell($lat, $lng), "hexagon $column,$row");
    }
  }

  public function testPointsInsideAHexagonMapToIt() {
    for($corner = 0; $corner < 6; $corner++) {
      $this->assertSame([237, 66], HexGrid::cell(...HexGrid::inside(237, 66, 0.9, $corner)));
      $this->assertSame([237, 66], HexGrid::cell(...HexGrid::inside(237, 66, 0.9, $corner, true)));
    }
  }

  public function testAHexagonIsOneDegreeOfLongitudeWide() {
    [$lat, $lng] = HexGrid::center(100, 20);
    $this->assertSame([100, 20], HexGrid::cell($lat, $lng + 0.49));
    $this->assertSame([101, 20], HexGrid::cell($lat, $lng + 0.51));
    $this->assertSame([99, 20], HexGrid::cell($lat, $lng - 0.51));
  }

  public function testCitiesFarApartAreInDifferentHexagons() {
    $vancouver = HexGrid::cell(49.2827, -123.1207);
    $toronto = HexGrid::cell(43.6532, -79.3832);
    $this->assertNotSame($vancouver, $toronto);
    // Two places in the same city share one
    $this->assertSame($vancouver, HexGrid::cell(49.26, -123.14));
  }

  public function testTheGridMeetsItselfAtTheAntimeridian() {
    $this->assertSame(HexGrid::cell(10, 179.9), HexGrid::cell(10, -179.9));
    foreach([-179.99, -90.5, 0.0, 90.5, 179.99] as $lng) {
      [$column] = HexGrid::cell(-17.7, $lng);
      $this->assertGreaterThanOrEqual(0, $column);
      $this->assertLessThan(360, $column);
    }
    // The centre of a hexagon on the seam has a longitude a map can use
    [, $lng] = HexGrid::center(359, 13);
    $this->assertGreaterThanOrEqual(-180, $lng);
    $this->assertLessThan(180, $lng);
  }

  public function testLatitudesBeyondTheMapAreClamped() {
    $this->assertSame(HexGrid::cell(85, 10), HexGrid::cell(89.9, 10));
    $this->assertSame(HexGrid::cell(-85, 10), HexGrid::cell(-90, 10));
  }

  public function testEveryGridMapsItsCentresBackAndWrapsAtTheAntimeridian() {
    foreach(HexGrid::LEVELS as $columns) {
      foreach([[0, 0], [$columns - 1, 7], [intdiv($columns, 2), -5], [3, 20]] as [$column, $row]) {
        [$lat, $lng] = HexGrid::center($column, $row, $columns);
        $this->assertSame([$column, $row], HexGrid::cell($lat, $lng, $columns), "$columns columns, hexagon $column,$row");
        $this->assertGreaterThanOrEqual(-180, $lng);
        $this->assertLessThan(180, $lng);
      }
      // In an even row a hexagon is centred on the antimeridian, so both sides of it are one hexagon
      [$lat] = HexGrid::center(0, 2, $columns);
      $this->assertSame([intdiv($columns, 2), 2], HexGrid::cell($lat, 179.99, $columns));
      $this->assertSame([intdiv($columns, 2), 2], HexGrid::cell($lat, -179.99, $columns));
    }
  }

  public function testCoarserGridsHaveWiderHexagons() {
    // A point 1.2 degrees east of the centre of a 4 degree hexagon is still in it,
    // but two hexagons over on the 1 degree grid
    [$lat, $lng] = HexGrid::center(20, 10, 90);
    $this->assertSame([20, 10], HexGrid::cell($lat, $lng + 1.2, 90));
    [$column, $row] = HexGrid::cell($lat, $lng, 360);
    $this->assertSame([$column + 1, $row], HexGrid::cell($lat, $lng + 1.2, 360));
  }

}
