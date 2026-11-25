<?php
namespace App\Stats\Geo;

// Country and city for a coordinate, using only data shipped with Compass.
//
// Answers are remembered per geohash-7 cell (roughly 150 m across) and are
// worked out at the centre of the cell, so the stored answer depends only on
// the cell and not on which point happened to be looked up first.
class PlaceResolver {

  const PRECISION = 7;
  // A point that falls in the water (a pier, a coarse coastline) still gets
  // the country of a city this close to it
  const COAST_FALLBACK_M = 15000;

  public function __construct(
    private CountryIndex $countries,
    private CityIndex $cities,
    private CellStore $cells,
  ) {}

  // Returns ['country' => code or null, 'city_id' => GeoNames id or null]
  public function resolve(float $lat, float $lng): array {
    $hash = Geo::geohash($lat, $lng, self::PRECISION);
    $cached = $this->cells->get($hash);
    if($cached !== false)
      return ['country' => $cached['country'], 'city_id' => $cached['city_id'] === null ? null : (int)$cached['city_id']];

    [$centerLat, $centerLng] = Geo::geohashCenter($hash);
    $country = $this->countries->lookup($centerLat, $centerLng);
    $city = $this->cities->nearest($centerLat, $centerLng);
    if($country === null && $city && $city['distance'] <= self::COAST_FALLBACK_M)
      $country = $city['country'];

    $place = ['country' => $country, 'city_id' => $city ? (int)$city['id'] : null];
    $this->cells->put($hash, $place);
    return $place;
  }

  // Country only, straight from the border index. Cheap enough to call for
  // sampled points along a move without going through the cell cache.
  public function country(float $lat, float $lng): ?string {
    return $this->countries->lookup($lat, $lng);
  }

}
