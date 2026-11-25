<?php
namespace App\Stats\Geo;

// Large and medium airports from OurAirports (public domain), used to tell a
// flight from any other gap in the data
class Airports {

  private ?array $grid = null;
  private string $file;

  public function __construct(?string $file = null) {
    $this->file = $file ?: resource_path('geo/airports.csv');
  }

  // Is there an airport within $metres of the point?
  public function near(float $lat, float $lng, float $metres = 10000): bool {
    $this->load();
    $row = (int)floor($lat);
    $column = (int)floor($lng);
    for($r = $row - 1; $r <= $row + 1; $r++) {
      for($c = $column - 1; $c <= $column + 1; $c++) {
        foreach($this->grid[$r][$c] ?? [] as [$aLat, $aLng]) {
          if(Geo::distance($lat, $lng, $aLat, $aLng) <= $metres)
            return true;
        }
      }
    }
    return false;
  }

  private function load(): void {
    if($this->grid !== null)
      return;
    $this->grid = [];
    $file = fopen($this->file, 'r');
    fgetcsv($file, null, ',', '"', '');
    while(($row = fgetcsv($file, null, ',', '"', '')) !== false) {
      $lat = (float)$row[3];
      $lng = (float)$row[4];
      $this->grid[(int)floor($lat)][(int)floor($lng)][] = [$lat, $lng];
    }
    fclose($file);
  }

}
