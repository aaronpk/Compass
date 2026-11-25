<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;

// Development tool: builds public/assets/world.geojson, the country outlines
// drawn on the world map page, from Natural Earth's 1:50m countries file.
// The built file is committed.
//
//   curl -LO https://raw.githubusercontent.com/nvkelso/natural-earth-vector/master/geojson/ne_50m_admin_0_countries.geojson
//   php artisan geo:build-world-map ne_50m_admin_0_countries.geojson
class GeoBuildWorldMap extends Command {

  protected $signature = 'geo:build-world-map {source : Path to ne_50m_admin_0_countries.geojson}';
  protected $description = 'Build the country outlines for the world map page from Natural Earth data';

  public function handle() {
    $source = json_decode(file_get_contents($this->argument('source')), true);
    if(!$source || !isset($source['features'])) {
      $this->error('Could not read the source file');
      return 1;
    }
    $features = [];
    foreach($source['features'] as $feature) {
      $p = $feature['properties'];
      // The same codes as geo:build-countries, so the map matches the stats
      $code = preg_match('/^[A-Z]{2}$/', $p['ISO_A2_EH']) ? $p['ISO_A2_EH'] : $p['ADM0_A3'];
      $polygons = $feature['geometry']['type'] == 'MultiPolygon' ? $feature['geometry']['coordinates'] : [$feature['geometry']['coordinates']];
      $out = [];
      foreach($polygons as $polygon) {
        $rings = [];
        foreach($polygon as $ring) {
          // Two decimals is about a kilometre: plenty for a map of the world
          $points = [];
          foreach($ring as $point) {
            $rounded = [round($point[0], 2), round($point[1], 2)];
            if(!$points || $points[count($points) - 1] !== $rounded)
              $points[] = $rounded;
          }
          if(count($points) >= 4)
            $rings[] = $points;
          elseif(!$rings)
            break; // the outline itself vanished, so drop its holes too
        }
        if($rings)
          $out[] = $rings;
      }
      if(!$out)
        continue;
      $features[] = [
        'type' => 'Feature',
        'properties' => ['code' => $code, 'name' => $p['NAME_LONG']],
        'geometry' => ['type' => 'MultiPolygon', 'coordinates' => $out],
      ];
    }
    $file = public_path('assets/world.geojson');
    file_put_contents($file, json_encode(['type' => 'FeatureCollection', 'features' => $features], JSON_UNESCAPED_UNICODE));
    $this->info(sprintf('%d outlines, %d KB', count($features), filesize($file) / 1024));
    return 0;
  }

}
