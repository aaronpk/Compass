<?php
namespace App\Stats\Geo;

use Illuminate\Support\Facades\DB;

class MysqlCityIndex extends CityIndex {

  protected function candidates(float $lat, float $lng): iterable {
    [$minLat, $maxLat, $ranges] = self::box($lat, $lng);
    $cities = [];
    foreach($ranges as [$minLng, $maxLng]) {
      $rows = DB::table('geo_cities')
        ->whereBetween('lat', [$minLat, $maxLat])
        ->whereBetween('lng', [$minLng, $maxLng])
        ->get();
      foreach($rows as $row)
        $cities[] = (array)$row;
    }
    return $cities;
  }

}
