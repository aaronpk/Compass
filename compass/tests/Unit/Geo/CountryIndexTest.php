<?php
namespace Tests\Unit\Geo;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Stats\Geo\CountryIndex;

class CountryIndexTest extends TestCase {

  private static CountryIndex $index;

  public static function setUpBeforeClass(): void {
    self::$index = new CountryIndex(dirname(__DIR__, 3).'/resources/geo');
  }

  public static function places(): array {
    return [
      'Portland' => [45.52, -122.68, 'US'],
      'Vancouver' => [49.28, -123.12, 'CA'],
      'Point Roberts, a US exclave reached through Canada' => [48.985, -123.07, 'US'],
      'Basel' => [47.56, 7.59, 'CH'],
      'Weil am Rhein, 4 km from Basel' => [47.593, 7.62, 'DE'],
      'Saint-Louis, 4 km from Basel' => [47.59, 7.56, 'FR'],
      'Maseru, inside the hole in South Africa' => [-29.31, 27.48, 'LS'],
      'Johannesburg' => [-26.20, 28.04, 'ZA'],
      'Vatican City' => [41.9029, 12.4534, 'VA'],
      'Rome' => [41.89, 12.49, 'IT'],
      'Paris (ISO_A2 is -99 in the source data)' => [48.85, 2.35, 'FR'],
      'Oslo (ISO_A2 is -99 in the source data)' => [59.91, 10.75, 'NO'],
      'Suva, west of the antimeridian' => [-18.14, 178.44, 'FJ'],
      'Taveuni, east of the antimeridian' => [-16.85, -179.97, 'FJ'],
      'South Pole' => [-89.9, 0, 'AQ'],
      'Kaliningrad exclave' => [54.71, 20.51, 'RU'],
      // Territories are kept separate from their sovereign country
      'San Juan' => [18.46, -66.10, 'PR'],
      'Hong Kong' => [22.30, 114.17, 'HK'],
      // Places with no ISO code keep Natural Earth's three-letter code
      'Pristina' => [42.66, 21.16, 'XK'],
      'Hargeisa, Somaliland' => [9.56, 44.06, 'SOL'],
      'North Nicosia, Northern Cyprus' => [35.19, 33.36, 'CYN'],
      // Open water
      'mid-Pacific' => [0, -140, null],
      'mid-Atlantic' => [30, -40, null],
      'Out of range' => [95, 0, null],
    ];
  }

  #[DataProvider('places')]
  public function testLookup(float $lat, float $lng, ?string $expected) {
    $this->assertSame($expected, self::$index->lookup($lat, $lng));
  }

  public function testPointsOnCellEdgesGiveAnAnswer() {
    // Cell boundaries fall on every half degree
    $this->assertSame('US', self::$index->lookup(45.5, -122.5));
    $this->assertSame('US', self::$index->lookup(45.0, -100.0));
    $this->assertSame('RU', self::$index->lookup(60.0, 100.0));
    $this->assertNull(self::$index->lookup(0.0, -140.0));
  }

  public function testCountriesListHasNamesAndContinents() {
    $countries = self::$index->countries();
    $this->assertSame('United States', $countries['US']['name']);
    $this->assertSame('North America', $countries['US']['continent']);
    $this->assertSame('Puerto Rico', $countries['PR']['name']);
    $this->assertSame('US1', $countries['PR']['sovereign']);
    $this->assertGreaterThan(240, count($countries));
  }

}
