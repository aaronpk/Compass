<?php
namespace App\Stats\Geo;

// Finds the city a coordinate belongs to, from the GeoNames list of places
// with a population of 5,000 or more.
//
// The nearest place isn't always the right answer: a small suburb's centre can
// be closer than the centre of the city you are standing in. So each place gets
// a rough radius from its population, and the winner is the place you are
// deepest inside, measured as distance divided by radius.
abstract class CityIndex {

  const SEARCH_KM = 50;

  // Returns [id, name, country, admin1, lat, lng, population, tz, distance] or null
  public function nearest(float $lat, float $lng): ?array {
    $best = null;
    $bestScore = INF;
    foreach($this->candidates($lat, $lng) as $city) {
      $distance = Geo::distance($lat, $lng, (float)$city['lat'], (float)$city['lng']) / 1000;
      $radius = self::radius((int)$city['population']);
      if($distance > max(10, 2 * $radius))
        continue;
      $score = $distance / $radius;
      if($score < $bestScore) {
        $bestScore = $score;
        $best = $city;
        $best['distance'] = $distance * 1000;
      }
    }
    return $best;
  }

  // Radius in km of a circle holding the population at 2,000 people per km²
  public static function radius(int $population): float {
    return sqrt(max($population, 1) / (2000 * M_PI));
  }

  // Cities within about SEARCH_KM of the point
  abstract protected function candidates(float $lat, float $lng): iterable;

  // The search box in degrees: [minLat, maxLat, [[minLng, maxLng], ...]].
  // Two longitude ranges are returned when the box crosses the antimeridian.
  protected static function box(float $lat, float $lng): array {
    $dLat = self::SEARCH_KM / 111.2;
    $dLng = min(180, $dLat / max(0.01, cos(deg2rad($lat))));
    $ranges = [[max(-180, $lng - $dLng), min(180, $lng + $dLng)]];
    if($lng - $dLng < -180)
      $ranges[] = [$lng - $dLng + 360, 180];
    if($lng + $dLng > 180)
      $ranges[] = [-180, $lng + $dLng - 360];
    return [$lat - $dLat, $lat + $dLat, $ranges];
  }

}
