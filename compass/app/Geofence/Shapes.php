<?php
namespace App\Geofence;

use App\Stats\Geo\Geo;
use InvalidArgumentException;

// The geometry of a fence: how far a point is from its edge, and whether a
// line passes through it. No database, no framework.
//
// A fence is an array:
//   circle:  ['kind' => 'circle', 'lat' => .., 'lng' => .., 'radius' => metres]
//   polygon: ['kind' => 'polygon', 'polygons' => [[ring, hole, ...], ...],
//             'bbox' => [minLat, maxLat, minLng, maxLng]]
// where a ring is a list of [lng, lat] pairs, as in GeoJSON.
class Shapes {

  const METRES_PER_DEGREE = 111195; // of latitude, on the mean sphere

  const MAX_VERTICES = 5000;

  // A point this far outside a polygon's bounding box is simply "far away";
  // the exact distance to the edge isn't worked out. Larger than the worst
  // accuracy Compass keeps (5 km) plus any margin.
  const FAR = 10000;

  // Metres from the point to the fence's edge: negative inside, positive outside
  public static function distance(array $fence, float $lat, float $lng): float {
    if($fence['kind'] == 'circle')
      return Geo::distance($lat, $lng, (float)$fence['lat'], (float)$fence['lng']) - $fence['radius'];

    $box = self::boxDistance($fence['bbox'], $lat, $lng);
    if($box > self::FAR)
      return $box;

    // Work on a flat plane centred on the point, in metres. Fences are small
    // next to the Earth, so this is accurate to well under a metre.
    $kx = self::METRES_PER_DEGREE * cos(deg2rad($lat));
    $ky = self::METRES_PER_DEGREE;
    $inside = false;
    $nearest = INF;
    foreach($fence['polygons'] as $rings) {
      foreach($rings as $ring) {
        $n = count($ring);
        $x1 = ($ring[$n - 1][0] - $lng) * $kx;
        $y1 = ($ring[$n - 1][1] - $lat) * $ky;
        for($i = 0; $i < $n; $i++) {
          $x2 = ($ring[$i][0] - $lng) * $kx;
          $y2 = ($ring[$i][1] - $lat) * $ky;
          // Ray casting: does a ray from the point towards +x cross this edge?
          // Counting every ring, holes included, gives the even-odd answer.
          if(($y1 > 0) != ($y2 > 0) && ($x1 + ($x2 - $x1) * (0 - $y1) / ($y2 - $y1)) > 0)
            $inside = !$inside;
          $nearest = min($nearest, self::pointToSegment(0, 0, $x1, $y1, $x2, $y2));
          $x1 = $x2;
          $y1 = $y2;
        }
      }
    }
    return $inside ? -$nearest : $nearest;
  }

  // A rough measure of a fence's size in metres: a circle's radius, or half
  // the shorter side of a polygon's bounding box
  public static function size(array $fence): float {
    if($fence['kind'] == 'circle')
      return (float)$fence['radius'];
    [$minLat, $maxLat, $minLng, $maxLng] = $fence['bbox'];
    $height = ($maxLat - $minLat) * self::METRES_PER_DEGREE;
    $width = ($maxLng - $minLng) * self::METRES_PER_DEGREE * cos(deg2rad(($minLat + $maxLat) / 2));
    return min($height, $width) / 2;
  }

  // [lat, lng] of the middle of the fence, for showing it on a map
  public static function center(array $fence): array {
    if($fence['kind'] == 'circle')
      return [(float)$fence['lat'], (float)$fence['lng']];
    [$minLat, $maxLat, $minLng, $maxLng] = $fence['bbox'];
    return [($minLat + $maxLat) / 2, ($minLng + $maxLng) / 2];
  }

  // Does the straight line between two points pass at least $depth metres
  // inside the fence? Checked at steps along the line, which is enough for a
  // fence that isn't much thinner than it is wide.
  public static function passesThrough(array $fence, float $lat1, float $lng1, float $lat2, float $lng2, float $depth = 0): bool {
    $length = Geo::distance($lat1, $lng1, $lat2, $lng2);
    if($length == 0)
      return false;
    if($fence['kind'] == 'polygon') {
      // Cheap rejection: the line's box and the fence's box don't meet
      [$minLat, $maxLat, $minLng, $maxLng] = $fence['bbox'];
      if(max($lat1, $lat2) < $minLat || min($lat1, $lat2) > $maxLat || max($lng1, $lng2) < $minLng || min($lng1, $lng2) > $maxLng)
        return false;
    } else {
      $reach = $fence['radius'] + $length;
      if(Geo::distance($lat1, $lng1, (float)$fence['lat'], (float)$fence['lng']) > $reach)
        return false;
    }
    $step = max(5, self::size($fence) / 4);
    $steps = (int)min(400, ceil($length / $step));
    for($i = 1; $i < $steps; $i++) {
      $f = $i / $steps;
      if(self::distance($fence, $lat1 + ($lat2 - $lat1) * $f, $lng1 + ($lng2 - $lng1) * $f) <= -$depth)
        return true;
    }
    return false;
  }

  // Turns GeoJSON text (a Polygon or MultiPolygon, bare or inside a Feature
  // or FeatureCollection) into ['geometry' => clean GeoJSON geometry,
  // 'polygons' => ..., 'bbox' => ...]. Throws InvalidArgumentException with a
  // message fit to show the person who pasted it.
  public static function parseGeoJSON(string $text): array {
    $data = json_decode($text, true);
    if(!is_array($data))
      throw new InvalidArgumentException('That is not valid JSON.');

    $polygons = [];
    $collect = function($node) use(&$collect, &$polygons) {
      if(!is_array($node))
        return;
      switch($node['type'] ?? null) {
        case 'FeatureCollection':
          foreach($node['features'] ?? [] as $feature)
            $collect($feature);
          break;
        case 'Feature':
          $collect($node['geometry'] ?? null);
          break;
        case 'GeometryCollection':
          foreach($node['geometries'] ?? [] as $geometry)
            $collect($geometry);
          break;
        case 'Polygon':
          $polygons[] = $node['coordinates'] ?? null;
          break;
        case 'MultiPolygon':
          foreach($node['coordinates'] ?? [] as $polygon)
            $polygons[] = $polygon;
          break;
      }
    };
    $collect($data);
    if(!$polygons)
      throw new InvalidArgumentException('The GeoJSON needs to contain a Polygon or MultiPolygon.');

    $clean = [];
    $vertices = 0;
    $minLat = $minLng = INF;
    $maxLat = $maxLng = -INF;
    foreach($polygons as $polygon) {
      if(!is_array($polygon) || !$polygon)
        throw new InvalidArgumentException('A polygon in the GeoJSON has no coordinates.');
      $rings = [];
      foreach($polygon as $ring) {
        if(!is_array($ring))
          throw new InvalidArgumentException('A polygon in the GeoJSON has no coordinates.');
        $points = [];
        foreach($ring as $position) {
          if(!is_array($position) || !isset($position[0], $position[1]) || !is_numeric($position[0]) || !is_numeric($position[1]))
            throw new InvalidArgumentException('Every position needs to be [longitude, latitude].');
          $lng = round((float)$position[0], 6);
          $lat = round((float)$position[1], 6);
          if($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
            throw new InvalidArgumentException('A coordinate is out of range. GeoJSON positions are [longitude, latitude].');
          $points[] = [$lng, $lat];
          $minLat = min($minLat, $lat);
          $maxLat = max($maxLat, $lat);
          $minLng = min($minLng, $lng);
          $maxLng = max($maxLng, $lng);
        }
        // Stored closed, as GeoJSON has it
        if($points && $points[0] !== $points[count($points) - 1])
          $points[] = $points[0];
        if(count($points) < 4)
          throw new InvalidArgumentException('A polygon needs at least three corners.');
        $vertices += count($points);
        $rings[] = $points;
      }
      $clean[] = $rings;
    }
    if($vertices > self::MAX_VERTICES)
      throw new InvalidArgumentException('That shape has '.$vertices.' points. The most a fence can have is '.self::MAX_VERTICES.'; simplify it first.');
    if($maxLng - $minLng > 180)
      throw new InvalidArgumentException('A fence can\'t cross the 180° meridian.');

    $geometry = count($clean) == 1
      ? ['type' => 'Polygon', 'coordinates' => $clean[0]]
      : ['type' => 'MultiPolygon', 'coordinates' => $clean];
    return ['geometry' => $geometry, 'polygons' => $clean, 'bbox' => [$minLat, $maxLat, $minLng, $maxLng]];
  }

  // The fence array for a row of the geofences table, or null for a row that
  // has no shape (a place watch, or damaged data)
  public static function fromRow(object $row): ?array {
    if($row->kind == 'circle') {
      if($row->lat === null || $row->lng === null || !$row->radius)
        return null;
      return ['kind' => 'circle', 'lat' => (float)$row->lat, 'lng' => (float)$row->lng, 'radius' => (float)$row->radius];
    }
    if($row->kind == 'polygon') {
      try {
        $parsed = self::parseGeoJSON((string)$row->geojson);
      } catch(InvalidArgumentException $e) {
        return null;
      }
      return ['kind' => 'polygon', 'polygons' => $parsed['polygons'], 'bbox' => $parsed['bbox']];
    }
    return null;
  }

  // Metres from a point to a bounding box; 0 inside it
  private static function boxDistance(array $bbox, float $lat, float $lng): float {
    [$minLat, $maxLat, $minLng, $maxLng] = $bbox;
    $dLat = max($minLat - $lat, 0, $lat - $maxLat) * self::METRES_PER_DEGREE;
    $dLng = max($minLng - $lng, 0, $lng - $maxLng) * self::METRES_PER_DEGREE * cos(deg2rad($lat));
    return sqrt($dLat * $dLat + $dLng * $dLng);
  }

  private static function pointToSegment(float $px, float $py, float $x1, float $y1, float $x2, float $y2): float {
    $dx = $x2 - $x1;
    $dy = $y2 - $y1;
    $length2 = $dx * $dx + $dy * $dy;
    $t = $length2 == 0 ? 0 : max(0, min(1, (($px - $x1) * $dx + ($py - $y1) * $dy) / $length2));
    return hypot($px - ($x1 + $t * $dx), $py - ($y1 + $t * $dy));
  }

}
