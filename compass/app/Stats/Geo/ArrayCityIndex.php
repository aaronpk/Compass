<?php
namespace App\Stats\Geo;

// A CityIndex over a plain list of cities, for tests and one-off scripts
class ArrayCityIndex extends CityIndex {

  private array $cities;

  // Each city: [id, name, country, admin1, lat, lng, population, tz]
  public function __construct(array $cities) {
    $this->cities = $cities;
  }

  protected function candidates(float $lat, float $lng): iterable {
    [$minLat, $maxLat, $ranges] = self::box($lat, $lng);
    foreach($this->cities as $city) {
      if($city['lat'] < $minLat || $city['lat'] > $maxLat)
        continue;
      foreach($ranges as [$minLng, $maxLng]) {
        if($city['lng'] >= $minLng && $city['lng'] <= $maxLng) {
          yield $city;
          break;
        }
      }
    }
  }

}
