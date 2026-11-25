<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Stats\Geo\CountryIndex;
use App\Stats\Geo\HexGrid;

// Works out which hexagons of the world map are on land, and in which
// country, for each of the map's grids,
// and writes them to public/assets/world-hex.json and world-hex-fine.json. The result is
// committed, so this only needs running again when the grid or the country
// index changes.
class GeoBuildHexGrid extends Command {

  // Antarctica is left out: stretched by the projection it would be a third of the file
  const SOUTH = -60.0;
  const NORTH = 84.0;

  protected $signature = 'geo:build-hex-grid';
  protected $description = 'Build the land hexagons for the world map page from the country index';

  public function handle(CountryIndex $countries) {
    $levels = [];
    foreach(HexGrid::LEVELS as $columns) {
      [$byCountry, $total] = $this->level($countries, $columns);
      $levels[$columns] = $byCountry;
      $this->info(sprintf('%d columns: %d hexagons in %d countries', $columns, $total, count($byCountry)));
    }
    // The finest grid is the biggest by far and only needed when zoomed in, so it gets its own file.
    // {"levels": {"360": {"CA": [column, row, column, row, ...]}}}
    $fine = max(HexGrid::LEVELS);
    foreach(['world-hex.json' => array_diff_key($levels, [$fine => true]), 'world-hex-fine.json' => [$fine => $levels[$fine]]] as $name => $content) {
      $file = public_path('assets/'.$name);
      file_put_contents($file, json_encode(['levels' => $content]));
      $this->info(sprintf('%s: %d KB', $name, filesize($file) / 1024));
    }
    return 0;
  }

  private function level(CountryIndex $countries, int $columns): array {
    [$first, $last] = HexGrid::rows(self::SOUTH, self::NORTH, $columns);
    $byCountry = [];
    $total = 0;
    for($row = $first; $row <= $last; $row++) {
      for($column = 0; $column < $columns; $column++) {
        // A hexagon belongs to the country most of it is in, and is land if any of it is
        $found = [];
        [$lat, $lng] = HexGrid::center($column, $row, $columns);
        if($code = $countries->lookup($lat, $lng))
          $found[$code] = 2;
        for($corner = 0; $corner < 6; $corner++) {
          foreach([[0.55, false], [0.9, false], [0.9, true]] as [$fraction, $edge]) {
            [$lat, $lng] = HexGrid::inside($column, $row, $fraction, $corner, $edge, $columns);
            if($code = $countries->lookup($lat, $lng))
              $found[$code] = ($found[$code] ?? 0) + 1;
          }
        }
        if(!$found)
          continue;
        arsort($found);
        $code = array_key_first($found);
        $byCountry[$code][] = $column;
        $byCountry[$code][] = $row;
        $total++;
      }
    }
    ksort($byCountry);
    return [$byCountry, $total];
  }

}
