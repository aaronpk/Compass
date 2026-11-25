<?php
namespace Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use App\Stats\Geo\ArrayCityIndex;
use App\Stats\Geo\CityIndex;

class CityIndexTest extends TestCase {

  private function index(): CityIndex {
    return new ArrayCityIndex([
      ['id' => 1, 'name' => 'Portland', 'country' => 'US', 'admin1' => 'OR', 'lat' => 45.52345, 'lng' => -122.67621, 'population' => 650000, 'tz' => 'America/Los_Angeles'],
      // A small suburb whose centre is 9 km from Portland's
      ['id' => 2, 'name' => 'Milwaukie', 'country' => 'US', 'admin1' => 'OR', 'lat' => 45.44623, 'lng' => -122.63926, 'population' => 21000, 'tz' => 'America/Los_Angeles'],
      ['id' => 3, 'name' => 'Suva', 'country' => 'FJ', 'admin1' => null, 'lat' => -18.14161, 'lng' => 178.44149, 'population' => 77000, 'tz' => 'Pacific/Fiji'],
      ['id' => 4, 'name' => 'Somewhere at 179.9 E', 'country' => 'FJ', 'admin1' => null, 'lat' => -16.8, 'lng' => 179.95, 'population' => 6000, 'tz' => 'Pacific/Fiji'],
    ]);
  }

  public function testBigCityWinsOverACloserSuburbCentre() {
    // 5.5 km from Portland's centre and 3.6 km from Milwaukie's, but well
    // inside Portland and outside little Milwaukie
    $city = $this->index()->nearest(45.478, -122.650);
    $this->assertSame('Portland', $city['name']);
  }

  public function testStandingInTheSuburbReturnsTheSuburb() {
    $city = $this->index()->nearest(45.4465, -122.6390);
    $this->assertSame('Milwaukie', $city['name']);
    $this->assertLessThan(100, $city['distance']);
  }

  public function testNothingWithinRangeReturnsNull() {
    // 60 km east of Portland, in the mountains
    $this->assertNull($this->index()->nearest(45.37, -121.70));
    $this->assertNull($this->index()->nearest(0, -140));
  }

  public function testASmallTownOnlyReachesTenKilometres() {
    // 8 km from the small town at 179.95 E is accepted, 14 km is not
    $this->assertSame(4, $this->index()->nearest(-16.8, 179.875)['id']);
    $this->assertNull($this->index()->nearest(-16.8, 179.82));
  }

  public function testSearchCrossesTheAntimeridian() {
    // 6 km away, on the other side of 180 degrees
    $city = $this->index()->nearest(-16.8, -179.995);
    $this->assertSame(4, $city['id']);
  }

  public function testRadiusGrowsWithPopulation() {
    $this->assertEqualsWithDelta(0.89, CityIndex::radius(5000), 0.01);
    $this->assertEqualsWithDelta(10.2, CityIndex::radius(650000), 0.1);
  }

}
