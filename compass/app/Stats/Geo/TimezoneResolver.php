<?php
namespace App\Stats\Geo;

// The timezone in effect at a coordinate.
//
// The underlying lookup (p3k/timezone: nearest city within 200 km) scans its
// whole list on every call, so answers are remembered per geohash-4 cell
// (about 39 x 19 km). Returns null over open ocean; the caller decides what to
// fall back to, normally the last timezone it saw and then the database's
// configured timezone.
class TimezoneResolver {

  const PRECISION = 4;

  private $lookup;

  public function __construct(private CellStore $cells, ?callable $lookup = null) {
    $this->lookup = $lookup ?: fn($lat, $lng) => \p3k\Timezone::timezone_for_location($lat, $lng);
  }

  public function resolve(float $lat, float $lng): ?string {
    $hash = Geo::geohash($lat, $lng, self::PRECISION);
    $cached = $this->cells->get($hash);
    if($cached !== false)
      return $cached['tz'];

    [$centerLat, $centerLng] = Geo::geohashCenter($hash);
    $tz = ($this->lookup)($centerLat, $centerLng) ?: null;
    $this->cells->put($hash, ['tz' => $tz]);
    return $tz;
  }

}
