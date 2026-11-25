<?php
namespace App\Stats\Geo;

// Small geographic helpers with no dependencies
class Geo {

  const EARTH_RADIUS = 6371008.8; // mean radius in metres

  private const GEOHASH_ALPHABET = '0123456789bcdefghjkmnpqrstuvwxyz';

  // Great-circle distance in metres. Unlike gc_distance() in p3k/utils this
  // returns 0 for identical points instead of NaN, and uses the mean radius.
  public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $phi1 = deg2rad($lat1);
    $phi2 = deg2rad($lat2);
    $dphi = $phi2 - $phi1;
    $dlambda = deg2rad($lng2 - $lng1);
    $a = sin($dphi / 2) ** 2 + cos($phi1) * cos($phi2) * sin($dlambda / 2) ** 2;
    return 2 * self::EARTH_RADIUS * asin(min(1.0, sqrt($a)));
  }

  public static function geohash(float $lat, float $lng, int $precision): string {
    $latRange = [-90.0, 90.0];
    $lngRange = [-180.0, 180.0];
    $hash = '';
    $bits = 0;
    $value = 0;
    $even = true; // even bits encode longitude
    while(strlen($hash) < $precision) {
      if($even) {
        $mid = ($lngRange[0] + $lngRange[1]) / 2;
        if($lng >= $mid) { $value = ($value << 1) | 1; $lngRange[0] = $mid; }
        else { $value = $value << 1; $lngRange[1] = $mid; }
      } else {
        $mid = ($latRange[0] + $latRange[1]) / 2;
        if($lat >= $mid) { $value = ($value << 1) | 1; $latRange[0] = $mid; }
        else { $value = $value << 1; $latRange[1] = $mid; }
      }
      $even = !$even;
      if(++$bits == 5) {
        $hash .= self::GEOHASH_ALPHABET[$value];
        $bits = 0;
        $value = 0;
      }
    }
    return $hash;
  }

  // The centre of a geohash cell, as [lat, lng]
  public static function geohashCenter(string $hash): array {
    $latRange = [-90.0, 90.0];
    $lngRange = [-180.0, 180.0];
    $even = true;
    foreach(str_split($hash) as $char) {
      $value = strpos(self::GEOHASH_ALPHABET, $char);
      for($bit = 4; $bit >= 0; $bit--) {
        $on = ($value >> $bit) & 1;
        if($even) {
          $mid = ($lngRange[0] + $lngRange[1]) / 2;
          if($on) $lngRange[0] = $mid; else $lngRange[1] = $mid;
        } else {
          $mid = ($latRange[0] + $latRange[1]) / 2;
          if($on) $latRange[0] = $mid; else $latRange[1] = $mid;
        }
        $even = !$even;
      }
    }
    return [($latRange[0] + $latRange[1]) / 2, ($lngRange[0] + $lngRange[1]) / 2];
  }

}
