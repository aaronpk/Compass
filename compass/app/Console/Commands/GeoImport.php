<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Stats\Geo\CountryIndex;

// Loads the lookup tables used for stats: countries from the built index in
// resources/geo, and cities from GeoNames (https://www.geonames.org, CC BY 4.0).
// Safe to run again; it replaces what is there.
class GeoImport extends Command {

  const CITIES_URL = 'https://download.geonames.org/export/dump/cities5000.zip';

  protected $signature = 'geo:import {cities? : Path to cities5000.txt or .zip; downloaded from GeoNames if not given}';
  protected $description = 'Import the country and city lookup tables';

  public function handle() {
    $countries = array_map(fn($c) => [
      'code' => $c['code'], 'iso3' => $c['iso3'], 'name' => $c['name'],
      'continent' => $c['continent'], 'sovereign' => $c['sovereign'],
    ], array_values((new CountryIndex)->countries()));
    DB::table('geo_countries')->delete();
    DB::table('geo_countries')->insert($countries);
    $this->info(count($countries).' countries');

    $path = $this->argument('cities');
    $temporary = [];
    if(!$path) {
      $path = tempnam(sys_get_temp_dir(), 'cities').'.zip';
      $temporary[] = $path;
      $this->info('Downloading '.self::CITIES_URL);
      if(!copy(self::CITIES_URL, $path)) {
        $this->error('Download failed');
        return 1;
      }
    }
    if(str_ends_with($path, '.zip')) {
      $zip = new \ZipArchive;
      if($zip->open($path) !== true) {
        $this->error('Could not open '.$path);
        return 1;
      }
      $dir = sys_get_temp_dir().'/cities-'.getmypid();
      $zip->extractTo($dir, 'cities5000.txt');
      $zip->close();
      $path = $dir.'/cities5000.txt';
      $temporary[] = $path;
    }

    $file = fopen($path, 'r');
    if(!$file) {
      $this->error('Could not read '.$path);
      return 1;
    }
    $count = 0;
    $batch = [];
    DB::transaction(function() use($file, &$count, &$batch) {
      DB::table('geo_cities')->delete();
      while(($line = fgets($file)) !== false) {
        // GeoNames "geoname" table: tab separated, no quoting
        $f = explode("\t", rtrim($line, "\r\n"));
        if(count($f) < 18)
          continue;
        // PPLX is a section of a populated place (a neighbourhood)
        if($f[7] == 'PPLX')
          continue;
        $batch[] = [
          'id' => (int)$f[0], 'name' => $f[1], 'country' => $f[8], 'admin1' => $f[10] === '' ? null : $f[10],
          'lat' => (float)$f[4], 'lng' => (float)$f[5], 'population' => (int)$f[14], 'tz' => $f[17] === '' ? null : $f[17],
        ];
        $count++;
        if(count($batch) == 1000) {
          DB::table('geo_cities')->insert($batch);
          $batch = [];
        }
      }
      if($batch)
        DB::table('geo_cities')->insert($batch);
    });
    fclose($file);
    foreach($temporary as $t)
      @unlink($t);

    // Cached cells may name cities from the previous import
    DB::table('geo_cells')->delete();

    $this->info($count.' cities');
    return 0;
  }

}
