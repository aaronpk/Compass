<?php
namespace App\Stats\Geo;

// The hexagons of the world map. They are laid out in the Web Mercator plane,
// so they are regular hexagons on the map, and cover less ground the further
// they are from the equator.
//
// The map uses several grids, coarser ones when zoomed out. A grid is named by
// how many columns go around the world: 360 columns makes hexagons one degree
// of longitude wide. The column counts divide 360 evenly, so every grid meets
// itself at the antimeridian.
//
// A hexagon is named by its column and row. Rows run south to north with row 0
// centred on the equator; odd rows sit half a column to the east.
//
// public/assets/world.js draws the same grid; keep the two in step.
class HexGrid {

  // The grids the map uses, coarsest first: hexagons 4, 2, 1 and half a degree wide
  const LEVELS = [90, 180, 360, 720];
  const MAX_LAT = 85.0;

  // Centre to corner, in degrees of longitude: the hexagons point up
  private static function size(int $columns): float {
    return 360 / $columns / M_SQRT3;
  }

  // [column, row] of the hexagon a point is in
  public static function cell(float $lat, float $lng, int $columns = 360): array {
    $size = self::size($columns);
    $x = $lng;
    $y = self::mercator($lat);
    // Fractional axial coordinates, then round to the nearest hexagon
    $q = (M_SQRT3 / 3 * $x - $y / 3) / $size;
    $r = (2 / 3 * $y) / $size;
    $s = -$q - $r;
    $rq = round($q);
    $rr = round($r);
    $rs = round($s);
    $dq = abs($rq - $q);
    $dr = abs($rr - $r);
    $ds = abs($rs - $s);
    if($dq > $dr && $dq > $ds)
      $rq = -$rr - $rs;
    elseif($dr > $ds)
      $rr = -$rq - $rs;
    $row = (int)$rr;
    $column = (int)$rq + intdiv($row - ($row & 1), 2);
    return [(($column % $columns) + $columns) % $columns, $row];
  }

  // [lat, lng] of a hexagon's centre
  public static function center(int $column, int $row, int $columns = 360): array {
    [$x, $y] = self::centerXY($column, $row, $columns);
    return [self::latitude($y), $x >= 180 ? $x - 360 : $x];
  }

  // [lat, lng] of a point inside a hexagon: $fraction of the way from the
  // centre to corner $corner (0 to 5), or to the middle of the edge after it
  public static function inside(int $column, int $row, float $fraction, int $corner, bool $edge = false, int $columns = 360): array {
    [$x, $y] = self::centerXY($column, $row, $columns);
    $angle = deg2rad(30 + 60 * $corner + ($edge ? 30 : 0));
    $reach = self::size($columns) * $fraction * ($edge ? M_SQRT3 / 2 : 1);
    $x += $reach * cos($angle);
    $y += $reach * sin($angle);
    if($x >= 180)
      $x -= 360;
    return [self::latitude($y), $x];
  }

  // The rows that cover a range of latitudes
  public static function rows(float $south, float $north, int $columns = 360): array {
    $step = self::size($columns) * 1.5;
    return [(int)ceil(self::mercator($south) / $step), (int)floor(self::mercator($north) / $step)];
  }

  private static function centerXY(int $column, int $row, int $columns): array {
    $size = self::size($columns);
    return [$size * M_SQRT3 * ($column + 0.5 * ($row & 1)), $size * 1.5 * $row];
  }

  // Mercator's y for a latitude, in the same units as longitude (degrees)
  private static function mercator(float $lat): float {
    $lat = max(-self::MAX_LAT, min(self::MAX_LAT, $lat));
    return rad2deg(log(tan(M_PI / 4 + deg2rad($lat) / 2)));
  }

  private static function latitude(float $y): float {
    return rad2deg(2 * atan(exp(deg2rad($y))) - M_PI / 2);
  }

}
