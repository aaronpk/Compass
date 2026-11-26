<?php
namespace App\Geofence;

use App\Stats\Geo\Geo;

// The airports Compass notices arriving at and leaving: every airport with an
// IATA code, from the list in Atlas (https://github.com/aaronpk/Atlas,
// data/airports-compact.csv). The list only has a point for each, so how far
// an airport reaches is assumed from its size, which comes from the large
// and medium airports of OurAirports (resources/geo/airports.csv) where the
// code and position agree. The rest count as small.
class Airports {

  // Metres from the airport's reference point that count as being there
  const RADIUS = ['L' => 2500, 'M' => 1500, 'S' => 750];

  private ?array $grid = null;
  private string $file;

  public function __construct(?string $file = null) {
    $this->file = $file ?: resource_path('geo/airport-places.csv');
  }

  // The airport the point is at or closest to being at:
  // [code, name, metres outside its edge (negative when inside)],
  // or null when none is within $margin metres of its edge.
  public function at(float $lat, float $lng, float $margin = 500): ?array {
    $this->load();
    $best = null;
    $row = (int)floor($lat * 10);
    $column = (int)floor($lng * 10);
    // Cells are a tenth of a degree, at least 11 km north to south, but
    // narrow towards the poles
    $reach = cos(deg2rad(min(89, abs($lat)))) > 0.3 ? 1 : 4;
    for($r = $row - 1; $r <= $row + 1; $r++) {
      for($c = $column - $reach; $c <= $column + $reach; $c++) {
        foreach($this->grid[$r][$c] ?? [] as [$code, $name, $aLat, $aLng, $radius]) {
          $outside = Geo::distance($lat, $lng, $aLat, $aLng) - $radius;
          if($outside <= $margin && (!$best || $outside < $best[2]))
            $best = [$code, $name, $outside];
        }
      }
    }
    return $best;
  }

  private function load(): void {
    if($this->grid !== null)
      return;
    $this->grid = [];
    // iata,size,lat,lng,name with nothing quoted: the name is the rest of
    // the line, commas and all. Read on every request that brings points,
    // so it is kept quick to take apart.
    $file = fopen($this->file, 'r');
    fgets($file);
    while(($line = fgets($file)) !== false) {
      $row = explode(',', rtrim($line, "\r\n"), 5);
      if(count($row) < 5)
        continue;
      [$code, $size, $lat, $lng, $name] = $row;
      $this->grid[(int)floor($lat * 10)][(int)floor($lng * 10)][] = [$code, $name, (float)$lat, (float)$lng, self::RADIUS[$size] ?? self::RADIUS['S']];
    }
    fclose($file);
  }

}
