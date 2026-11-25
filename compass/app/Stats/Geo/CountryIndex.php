<?php
namespace App\Stats\Geo;

// Finds the country for a coordinate from files built by geo:build-countries.
//
// The world is cut into a grid of half-degree cells. countries.idx holds one
// 32-bit value per cell: 0 for open ocean, 1-999 for a cell that lies entirely
// inside one country, and 1000 + a byte offset into countries.dat for a cell
// that contains a border or coastline. A record in countries.dat lists the
// country shapes clipped to that cell, with coordinates stored as 16-bit
// fractions of the cell.
class CountryIndex {

  const CELLS_PER_DEGREE = 2;
  const COLUMNS = 720;
  const ROWS = 360;
  const MIXED = 1000;

  private string $dir;
  private ?string $idx = null;
  private ?string $dat = null;
  private ?array $countries = null;

  public function __construct(?string $dir = null) {
    $this->dir = $dir ?: resource_path('geo');
  }

  // Returns the country code (ISO 3166-1 alpha-2 where one exists, otherwise
  // Natural Earth's three-letter code) or null over open water
  public function lookup(float $lat, float $lng): ?string {
    if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
      return null;

    $this->load();

    $u = ($lng + 180) * self::CELLS_PER_DEGREE;
    $v = ($lat + 90) * self::CELLS_PER_DEGREE;
    $cx = min(self::COLUMNS - 1, (int)floor($u));
    $cy = min(self::ROWS - 1, (int)floor($v));

    $value = unpack('V', $this->idx, ($cy * self::COLUMNS + $cx) * 4)[1];
    if($value == 0)
      return null;
    if($value < self::MIXED)
      return $this->countries[$value]['code'];

    // Border cell: test the point against each shape. A country's rings are
    // counted together so that holes (enclaves, lakes cut out of a country) work.
    $x = ($u - $cx) * 65535;
    $y = ($v - $cy) * 65535;
    $offset = $value - self::MIXED;
    $rings = unpack('v', $this->dat, $offset)[1];
    $offset += 2;
    $inside = [];
    for($r = 0; $r < $rings; $r++) {
      [, $country, $vertices] = unpack('v2', $this->dat, $offset);
      $offset += 4;
      $points = unpack('v'.($vertices * 2), $this->dat, $offset);
      $offset += $vertices * 4;
      if(self::pointInRing($x, $y, $points, $vertices))
        $inside[$country] = !($inside[$country] ?? false);
    }
    foreach($inside as $country => $in) {
      if($in)
        return $this->countries[$country]['code'];
    }
    return null;
  }

  // All countries in the index, keyed by code
  public function countries(): array {
    $this->load();
    $byCode = [];
    foreach($this->countries as $country)
      $byCode[$country['code']] = $country;
    return $byCode;
  }

  // $points is the 1-based array unpack() returns: x1, y1, x2, y2, ...
  private static function pointInRing(float $x, float $y, array $points, int $vertices): bool {
    $inside = false;
    $jx = $points[$vertices * 2 - 1];
    $jy = $points[$vertices * 2];
    for($i = 1; $i <= $vertices * 2; $i += 2) {
      $ix = $points[$i];
      $iy = $points[$i + 1];
      if(($iy > $y) != ($jy > $y) && $x < ($jx - $ix) * ($y - $iy) / ($jy - $iy) + $ix)
        $inside = !$inside;
      $jx = $ix;
      $jy = $iy;
    }
    return $inside;
  }

  private function load(): void {
    if($this->idx !== null)
      return;
    $this->idx = file_get_contents($this->dir.'/countries.idx');
    $this->dat = file_get_contents($this->dir.'/countries.dat');
    $this->countries = [];
    foreach(json_decode(file_get_contents($this->dir.'/countries.json'), true) as $country)
      $this->countries[$country['num']] = $country;
  }

}
