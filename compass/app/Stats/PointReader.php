<?php
namespace App\Stats;

// Reads one database's day files into compact arrays.
//
// A day file is always read whole and sorted, because late data is appended
// out of order. Each point is a plain list to keep memory down:
//   [time, lat, lng, accuracy, speed, altitude, motion]
// Unknown values are null. Speed is in m/s.
class PointReader {

  const T = 0, LAT = 1, LNG = 2, ACC = 3, SPEED = 4, ALT = 5, MOTION = 6;

  // Points less accurate than this are ignored everywhere in Compass
  const MAX_ACCURACY = 5000;

  private string $dir;
  private array $cache = [];

  public function __construct(string $databaseDir) {
    $this->dir = rtrim($databaseDir, '/');
  }

  public function path(string $date): string {
    return $this->dir.'/'.substr($date, 0, 4).'/'.substr($date, 5, 2).'/'.substr($date, 8, 2).'.txt';
  }

  // Returns ['points' => [...], 'trips' => [...], 'lines' => int, 'size' => int, 'mtime' => int],
  // or null when there is no file for that day. $date is Y-m-d (UTC).
  public function day(string $date): ?array {
    if(array_key_exists($date, $this->cache))
      return $this->cache[$date];

    $path = $this->path($date);
    $day = is_file($path) ? self::read($path, $date) : null;

    // Keep the last few days: processing one day looks at its neighbours,
    // and a backfill moves forward a day at a time
    if(count($this->cache) >= 4)
      array_shift($this->cache);
    return $this->cache[$date] = $day;
  }

  private static function read(string $path, string $date): array {
    $midnight = strtotime($date.' 00:00:00 UTC');
    $points = [];
    $trips = [];
    $lines = 0;
    $seen = [];

    $file = fopen($path, 'r');
    while(($line = fgets($file)) !== false) {
      if(strlen($line) < 30)
        continue;
      $lines++;
      $record = json_decode(substr($line, 27), true);
      if(!is_array($record))
        continue;
      $properties = $record['properties'] ?? [];
      if(!is_array($properties))
        continue;

      if(($properties['type'] ?? null) === 'trip') {
        if($trip = self::trip($properties))
          $trips[] = $trip;
        continue;
      }
      // App events (visits, pauses, launches) carry no usable position
      if(isset($properties['action']))
        continue;

      $coordinates = $record['geometry']['coordinates'] ?? null;
      if(!is_array($coordinates) || !isset($coordinates[0], $coordinates[1]) || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1]))
        continue;
      $lng = (float)$coordinates[0];
      $lat = (float)$coordinates[1];
      if(($lat == 0 && $lng == 0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
        continue;

      $accuracy = isset($properties['horizontal_accuracy']) && is_numeric($properties['horizontal_accuracy']) ? (float)$properties['horizontal_accuracy'] : null;
      if($accuracy !== null && ($accuracy > self::MAX_ACCURACY || $accuracy < 0))
        continue;

      // The line starts with "Y-m-d H:i:s.uuuuuu" in UTC, and the file only holds one day
      $time = $midnight + (int)substr($line, 11, 2) * 3600 + (int)substr($line, 14, 2) * 60 + (float)substr($line, 17, 9);

      // Two points in the same second: keep the more accurate one
      $second = (int)$time;
      if(isset($seen[$second])) {
        $previous = $points[$seen[$second]];
        if($accuracy === null || ($previous[self::ACC] !== null && $previous[self::ACC] <= $accuracy))
          continue;
        $index = $seen[$second];
      } else {
        $index = count($points);
        $seen[$second] = $index;
      }

      $speed = isset($properties['speed']) && is_numeric($properties['speed']) && $properties['speed'] >= 0 ? (float)$properties['speed'] : null;
      $altitude = isset($properties['altitude']) && is_numeric($properties['altitude']) ? (float)$properties['altitude'] : null;
      $points[$index] = [$time, $lat, $lng, $accuracy, $speed, $altitude, self::motion($properties['motion'] ?? null)];
    }
    fclose($file);

    usort($points, fn($a, $b) => $a[0] <=> $b[0]);

    return [
      'points' => $points,
      'trips' => $trips,
      'lines' => $lines,
      'size' => filesize($path),
      'mtime' => filemtime($path),
    ];
  }

  // Overland reports a list of activities; keep the most specific one
  private static function motion($motion): ?string {
    if(!is_array($motion) || !$motion)
      return null;
    foreach(['driving', 'cycling', 'running', 'walking', 'stationary'] as $label) {
      if(in_array($label, $motion))
        return $label;
    }
    return null;
  }

  // A trip record written by Overland when a trip ends:
  // [start, end, mode, reported distance, start lat, start lng, end lat, end lng]
  private static function trip(array $p): ?array {
    $start = isset($p['start']) ? strtotime((string)$p['start']) : false;
    $end = isset($p['end']) ? strtotime((string)$p['end']) : false;
    if(!$start || !$end || $end <= $start || empty($p['mode']) || !is_string($p['mode']))
      return null;
    $from = $p['start_location']['geometry']['coordinates'] ?? $p['start-coordinates'] ?? null;
    $to = $p['end_location']['geometry']['coordinates'] ?? $p['end-coordinates'] ?? null;
    $valid = fn($c) => is_array($c) && isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1]);
    return [
      'start' => (float)$start,
      'end' => (float)$end,
      'mode' => substr($p['mode'], 0, 10),
      'distance' => isset($p['distance']) && is_numeric($p['distance']) ? (float)$p['distance'] : null,
      'from' => $valid($from) ? [(float)$from[1], (float)$from[0]] : null,
      'to' => $valid($to) ? [(float)$to[1], (float)$to[0]] : null,
    ];
  }

}
