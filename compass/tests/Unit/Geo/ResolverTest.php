<?php
namespace Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use App\Stats\Geo\ArrayCellStore;
use App\Stats\Geo\ArrayCityIndex;
use App\Stats\Geo\CountryIndex;
use App\Stats\Geo\Geo;
use App\Stats\Geo\PlaceResolver;
use App\Stats\Geo\TimezoneResolver;

class ResolverTest extends TestCase {

  private function places(ArrayCellStore $store): PlaceResolver {
    return new PlaceResolver(
      new CountryIndex(dirname(__DIR__, 3).'/resources/geo'),
      new ArrayCityIndex([
        ['id' => 5746545, 'name' => 'Portland', 'country' => 'US', 'admin1' => 'OR', 'lat' => 45.52345, 'lng' => -122.67621, 'population' => 650000, 'tz' => 'America/Los_Angeles'],
        // Nuuk sits on a coast that the border data draws slightly inland
        ['id' => 3421319, 'name' => 'Nuuk', 'country' => 'GL', 'admin1' => null, 'lat' => 64.18347, 'lng' => -51.72157, 'population' => 18000, 'tz' => 'America/Nuuk'],
      ]),
      $store,
    );
  }

  public function testResolvesCountryAndCity() {
    $place = $this->places(new ArrayCellStore)->resolve(45.5152, -122.6784);
    $this->assertSame(['country' => 'US', 'city_id' => 5746545], $place);
  }

  public function testCountryWithoutACity() {
    // Central Oregon, far from the one city in the test list
    $place = $this->places(new ArrayCellStore)->resolve(43.8, -120.5);
    $this->assertSame(['country' => 'US', 'city_id' => null], $place);
  }

  public function testOpenOceanHasNeither() {
    $place = $this->places(new ArrayCellStore)->resolve(0, -140);
    $this->assertSame(['country' => null, 'city_id' => null], $place);
  }

  public function testAPointInTheWaterNextToACityGetsThatCitysCountry() {
    $resolver = $this->places(new ArrayCellStore);
    $this->assertNull($resolver->country(64.18, -51.72), 'Expected the border data to miss this point');
    $this->assertSame(['country' => 'GL', 'city_id' => 3421319], $resolver->resolve(64.18, -51.72));
  }

  public function testAnswersAreStoredPerCellAndReused() {
    $store = new ArrayCellStore;
    $resolver = $this->places($store);
    $resolver->resolve(45.5152, -122.6784);
    $this->assertCount(1, $store->cells);
    $hash = Geo::geohash(45.5152, -122.6784, 7);
    $this->assertSame(['country' => 'US', 'city_id' => 5746545], $store->cells[$hash]);

    // A stored answer wins, even a different one
    $store->cells[$hash] = ['country' => 'CA', 'city_id' => null];
    $this->assertSame(['country' => 'CA', 'city_id' => null], $resolver->resolve(45.5152, -122.6784));
  }

  public function testTimezoneIsLookedUpOncePerCell() {
    $calls = 0;
    $resolver = new TimezoneResolver(new ArrayCellStore, function($lat, $lng) use(&$calls) {
      $calls++;
      return $lng < -100 ? 'America/Los_Angeles' : null;
    });
    $this->assertSame('America/Los_Angeles', $resolver->resolve(45.5152, -122.6784));
    $this->assertSame('America/Los_Angeles', $resolver->resolve(45.52, -122.66));
    $this->assertSame(1, $calls);

    // "No timezone here" is remembered too
    $this->assertNull($resolver->resolve(30, -40));
    $this->assertNull($resolver->resolve(30, -40));
    $this->assertSame(2, $calls);
  }

  public function testTimezoneFromTheBundledData() {
    $resolver = new TimezoneResolver(new ArrayCellStore);
    $this->assertSame('America/Los_Angeles', $resolver->resolve(45.5152, -122.6784));
    $this->assertSame('Europe/Berlin', $resolver->resolve(52.52, 13.40));
    $this->assertNull($resolver->resolve(0, -140));
  }

}
