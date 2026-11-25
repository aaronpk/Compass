<?php
namespace App\Stats;

use App\Stats\Geo\Airports;
use App\Stats\Geo\Geo;
use App\Stats\PointReader as P;

// Splits a track into stops and moves.
//
// A stop is a stretch of at least five minutes spent within a small radius.
// Everything between two stops is a move. A move is cut where the data has a
// hole and the track resumes somewhere else; the jump across the hole becomes
// an "inferred" move, which is how a flight with the phone switched off shows up.
//
// Distance only accumulates inside moves, and only when the track has really
// left the last counted position, so GPS jitter while standing still adds nothing.
//
// The mode of a move comes from an Overland trip record when one covers it.
// Without one, the only mode worked out here is "plane"; other ground
// transport stays unlabelled (null).
class Segmenter {

  const BUCKET_SECONDS = 30;
  // Points less accurate than this (metres) don't shape the path
  const PATH_ACCURACY = 100;
  const MIN_STOP_SECONDS = 300;
  // A hole in the data longer than this...
  const GAP_SECONDS = 300;
  // ...is a jump when the track resumes at least this far away
  const GAP_METRES = 200;
  // A stop isn't stretched across more than this much silence
  const SILENT_STOP_SECONDS = 86400;
  // A new point counts once it is this far from the last counted one
  const MIN_HOP_METRES = 25;
  const MIN_TRIP_SECONDS = 15;

  public function __construct(private ?Airports $airports = null) {}

  // $points: sorted list from PointReader. $trips: trip records from PointReader.
  // Returns segments in time order. Each is an array with:
  //   type (stop|move), inferred, mode, mode_source (auto|trip), motion,
  //   start, end, lat, lng, end_lat, end_lng, radius, points, distance,
  //   reported_distance, speed_p50, speed_p95, alt_max,
  //   hops: [[start time, end time, metres, end lat, end lng], ...] for moves
  public function segment(array $points, array $trips = []): array {
    $path = [];
    foreach($points as $point) {
      if($point[P::ACC] === null || $point[P::ACC] <= self::PATH_ACCURACY)
        $path[] = $point;
    }
    if(!$path)
      return [];

    $radius = self::stayRadius($path);
    $buckets = self::buckets($path);
    $stops = self::stops($buckets, $radius);

    $segments = [];
    $times = array_column($path, P::T);
    $previous = null;
    foreach($stops as $stop) {
      $from = $previous ? $previous['end'] : $times[0];
      if($stop['start'] > $from) {
        foreach($this->moves($path, $times, $from, $stop['start']) as $move)
          $segments[] = $move;
      }
      $segments[] = $stop;
      $previous = $stop;
    }
    $from = $previous ? $previous['end'] : $times[0];
    $last = $times[count($times) - 1];
    if($last > $from) {
      foreach($this->moves($path, $times, $from, $last) as $move)
        $segments[] = $move;
    }

    foreach($trips as $trip)
      $segments = $this->applyTrip($segments, $trip, $path, $times);

    usort($segments, fn($a, $b) => $a['start'] <=> $b['start']);
    foreach($segments as &$segment) {
      if($segment['type'] == 'move')
        $this->finishMove($segment, $path, $times);
    }
    return $segments;
  }

  // Between 75 and 200 m, wider when the points themselves are less accurate
  private static function stayRadius(array $path): float {
    $accuracies = [];
    $step = max(1, intdiv(count($path), 500));
    for($i = 0; $i < count($path); $i += $step) {
      if($path[$i][P::ACC] !== null)
        $accuracies[] = $path[$i][P::ACC];
    }
    if(!$accuracies)
      return 100;
    sort($accuracies);
    $median = $accuracies[intdiv(count($accuracies), 2)];
    return max(75, min(2 * $median, 200));
  }

  // One position per 30 seconds (the median of the points in it), which
  // smooths out single stray points: [first time, last time, lat, lng, points]
  private static function buckets(array $path): array {
    $buckets = [];
    $key = null;
    $lats = $lngs = [];
    $first = $last = 0;
    $flush = function() use(&$buckets, &$lats, &$lngs, &$first, &$last) {
      if(!$lats)
        return;
      sort($lats);
      sort($lngs);
      $middle = intdiv(count($lats), 2);
      $buckets[] = [$first, $last, $lats[$middle], $lngs[$middle], count($lats)];
      $lats = $lngs = [];
    };
    foreach($path as $point) {
      $k = (int)floor($point[P::T] / self::BUCKET_SECONDS);
      if($k !== $key) {
        $flush();
        $key = $k;
        $first = $point[P::T];
      }
      $last = $point[P::T];
      $lats[] = $point[P::LAT];
      $lngs[] = $point[P::LNG];
    }
    $flush();
    return $buckets;
  }

  private static function stops(array $buckets, float $radius): array {
    $stops = [];
    $count = count($buckets);
    $i = 0;
    while($i < $count) {
      $lat = $buckets[$i][2];
      $lng = $buckets[$i][3];
      $members = 1;
      $points = $buckets[$i][4];
      $j = $i;
      while($j + 1 < $count
        && $buckets[$j + 1][0] - $buckets[$j][1] <= self::SILENT_STOP_SECONDS
        && Geo::distance($lat, $lng, $buckets[$j + 1][2], $buckets[$j + 1][3]) <= $radius) {
        $j++;
        $members++;
        $points += $buckets[$j][4];
        // Running centre of the stop
        $lat += ($buckets[$j][2] - $lat) / $members;
        $lng += ($buckets[$j][3] - $lng) / $members;
      }
      if($buckets[$j][1] - $buckets[$i][0] >= self::MIN_STOP_SECONDS) {
        $stops[] = self::stop($buckets[$i][0], $buckets[$j][1], $lat, $lng, $radius, $points, $i, $j);
        $i = $j + 1;
      } else {
        $i++;
      }
    }

    // Join stops that are really one: a moment of drift between them, or the
    // phone going quiet and coming back a few metres away
    $merged = [];
    foreach($stops as $stop) {
      $last = $merged ? $merged[count($merged) - 1] : null;
      if($last) {
        $apart = Geo::distance($last['lat'], $last['lng'], $stop['lat'], $stop['lng']);
        $between = $stop['start'] - $last['end'];
        $adjacent = $stop['first_bucket'] == $last['last_bucket'] + 1;
        if(($apart < 150 && $between < 120) || ($adjacent && $apart < self::GAP_METRES && $between <= self::SILENT_STOP_SECONDS)) {
          $weight = $last['points'] + $stop['points'];
          $last['lat'] = ($last['lat'] * $last['points'] + $stop['lat'] * $stop['points']) / $weight;
          $last['lng'] = ($last['lng'] * $last['points'] + $stop['lng'] * $stop['points']) / $weight;
          $last['end_lat'] = $last['lat'];
          $last['end_lng'] = $last['lng'];
          $last['end'] = $stop['end'];
          $last['points'] = $weight;
          $last['last_bucket'] = $stop['last_bucket'];
          $merged[count($merged) - 1] = $last;
          continue;
        }
      }
      $merged[] = $stop;
    }
    return $merged;
  }

  private static function stop($start, $end, $lat, $lng, $radius, $points, $firstBucket, $lastBucket): array {
    return [
      'type' => 'stop', 'inferred' => false, 'mode' => null, 'mode_source' => 'auto', 'motion' => null,
      'start' => $start, 'end' => $end, 'lat' => $lat, 'lng' => $lng, 'end_lat' => $lat, 'end_lng' => $lng,
      'radius' => $radius, 'points' => $points, 'distance' => 0.0, 'reported_distance' => null,
      'speed_p50' => null, 'speed_p95' => null, 'alt_max' => null, 'hops' => [],
      'first_bucket' => $firstBucket, 'last_bucket' => $lastBucket,
    ];
  }

  private static function move($start, $end, $lat, $lng, bool $inferred = false): array {
    return [
      'type' => 'move', 'inferred' => $inferred, 'mode' => null, 'mode_source' => 'auto', 'motion' => null,
      'start' => $start, 'end' => $end, 'lat' => $lat, 'lng' => $lng, 'end_lat' => $lat, 'end_lng' => $lng,
      'radius' => null, 'points' => 0, 'distance' => 0.0, 'reported_distance' => null,
      'speed_p50' => null, 'speed_p95' => null, 'alt_max' => null, 'hops' => [],
    ];
  }

  // The moves between $from and $to: usually one, more when the data has a
  // hole with a jump across it. The chain starts at the last point of the
  // stop before and ends at the first point of the stop after, so wandering
  // around inside a stop's radius adds no distance.
  private function moves(array $path, array $times, float $from, float $to, bool $splitAtGaps = true): array {
    $chain = [];
    for($i = self::firstAfter($times, $from, true); $i < count($path) && $path[$i][P::T] <= $to; $i++)
      $chain[] = $path[$i];
    if(count($chain) < 2)
      return [];

    $moves = [];
    $anchor = $chain[0];
    $previousTime = $anchor[P::T];
    $current = self::move($from, $to, $anchor[P::LAT], $anchor[P::LNG]);

    for($k = 1; $k < count($chain); $k++) {
      $point = $chain[$k];
      $sinceLast = $point[P::T] - $previousTime;
      $elapsed = $point[P::T] - $anchor[P::T];
      if($elapsed <= 0)
        continue;
      $distance = Geo::distance($anchor[P::LAT], $anchor[P::LNG], $point[P::LAT], $point[P::LNG]);

      if($sinceLast > self::GAP_SECONDS) {
        if($splitAtGaps) {
          // Close the move at the last point before the hole
          $current['end'] = $previousTime;
          $current['end_lat'] = $anchor[P::LAT];
          $current['end_lng'] = $anchor[P::LNG];
          if($current['end'] > $current['start'] || $current['hops'])
            $moves[] = $current;
          if($distance >= self::GAP_METRES) {
            $jump = self::move($previousTime, $point[P::T], $anchor[P::LAT], $anchor[P::LNG], true);
            $jump['end_lat'] = $point[P::LAT];
            $jump['end_lng'] = $point[P::LNG];
            $jump['hops'][] = [$previousTime, $point[P::T], $distance, $point[P::LAT], $point[P::LNG]];
            $jump['mode'] = $this->isFlight($distance, $sinceLast, $anchor, $point) ? 'plane' : null;
            $moves[] = $jump;
          }
          // Otherwise nothing is known about the silent time, and nothing is recorded for it
          $current = self::move($point[P::T], $to, $point[P::LAT], $point[P::LNG]);
        } elseif($distance >= self::GAP_METRES) {
          // Inside a trip the jump is simply part of the trip
          $current['hops'][] = [$previousTime, $point[P::T], $distance, $point[P::LAT], $point[P::LNG]];
        }
        $anchor = $point;
        $previousTime = $point[P::T];
        continue;
      }
      $previousTime = $point[P::T];

      $threshold = max(self::MIN_HOP_METRES, min(100, max($anchor[P::ACC] ?? 0, $point[P::ACC] ?? 0)));
      if($distance < $threshold)
        continue;
      $speed = $distance / $elapsed;
      // Faster than a train needs some sign of being in the air; faster than
      // an airliner is a glitch
      if($speed > 340)
        continue;
      if($speed > 100 && !(($point[P::SPEED] ?? 0) > 70 || ($point[P::ALT] ?? 0) > 3000))
        continue;
      // The speed the GPS itself reports is far steadier than positions are.
      // A hop much faster than both ends say they were going is a position jump.
      if($point[P::SPEED] !== null && $anchor[P::SPEED] !== null
        && $speed > 3 * max($point[P::SPEED], $anchor[P::SPEED]) + 10)
        continue;

      $current['hops'][] = [$anchor[P::T], $point[P::T], $distance, $point[P::LAT], $point[P::LNG]];
      $anchor = $point;
    }

    $current['end_lat'] = $anchor[P::LAT];
    $current['end_lng'] = $anchor[P::LNG];
    if($current['end'] > $current['start'])
      $moves[] = $current;
    return $moves;
  }

  // A jump across a hole in the data is a flight when it is long and fast,
  // or shorter but starts and ends at airports
  private function isFlight(float $metres, float $seconds, array $from, array $to): bool {
    $speed = $metres / max($seconds, 1);
    if($metres >= 100000 && $speed >= 50 && $speed <= 280)
      return true;
    if($metres >= 1500000 && $seconds <= 48 * 3600)
      return true;
    if($metres >= 300000 && $speed >= 28 && $this->airports
      && $this->airports->near($from[P::LAT], $from[P::LNG]) && $this->airports->near($to[P::LAT], $to[P::LNG]))
      return true;
    return false;
  }

  // Replaces whatever was detected during an Overland trip with one move
  // carrying the trip's mode
  private function applyTrip(array $segments, array $trip, array $path, array $times): array {
    $start = max($trip['start'], $times[0]);
    $end = min($trip['end'], $times[count($times) - 1]);
    if($end - $start < self::MIN_TRIP_SECONDS)
      return $segments;

    $result = [];
    foreach($segments as $segment) {
      if($segment['end'] <= $start || $segment['start'] >= $end) {
        $result[] = $segment;
        continue;
      }
      if($segment['inferred']) {
        // A jump is kept whole or not at all
        if($segment['start'] < $start)
          $result[] = $segment;
        continue;
      }
      if($segment['start'] < $start)
        $result[] = self::trim($segment, $segment['start'], $start);
      if($segment['end'] > $end)
        $result[] = self::trim($segment, $end, $segment['end']);
    }

    $moves = $this->moves($path, $times, $start, $end, false);
    $move = $moves ? $moves[0] : self::move($start, $end, $trip['from'][0] ?? 0, $trip['from'][1] ?? 0);
    $move['start'] = $start;
    $move['end'] = $end;
    $move['mode'] = $trip['mode'];
    $move['mode_source'] = 'trip';
    $move['reported_distance'] = $trip['distance'];
    $move['trip'] = $trip;
    $result[] = $move;
    return $result;
  }

  private static function trim(array $segment, float $start, float $end): array {
    $segment['start'] = $start;
    $segment['end'] = $end;
    if($segment['type'] == 'move') {
      $segment['hops'] = array_values(array_filter($segment['hops'], fn($hop) => $hop[0] >= $start && $hop[0] < $end));
      if($segment['hops']) {
        $last = $segment['hops'][count($segment['hops']) - 1];
        $segment['end_lat'] = $last[3];
        $segment['end_lng'] = $last[4];
      }
    }
    return $segment;
  }

  private function finishMove(array &$move, array $path, array $times): void {
    $distance = 0;
    $speeds = [];
    foreach($move['hops'] as $hop) {
      $distance += $hop[2];
      if($hop[1] > $hop[0])
        $speeds[] = $hop[2] / ($hop[1] - $hop[0]);
    }

    $points = 0;
    $altitude = null;
    $motion = [];
    for($i = self::firstAfter($times, $move['start'], true); $i < count($path) && $path[$i][P::T] <= $move['end']; $i++) {
      $points++;
      if($path[$i][P::ALT] !== null && ($altitude === null || $path[$i][P::ALT] > $altitude))
        $altitude = $path[$i][P::ALT];
      $label = $path[$i][P::MOTION];
      if($label !== null && $label != 'stationary')
        $motion[$label] = ($motion[$label] ?? 0) + 1;
    }
    $move['points'] = $points;
    $move['alt_max'] = $altitude;
    if($motion) {
      arsort($motion);
      // What Overland's motion sensor said for most of the move. Recorded as
      // an observation; it doesn't set the mode.
      $move['motion'] = key($motion);
    }

    if($speeds) {
      sort($speeds);
      $move['speed_p50'] = $speeds[intdiv(count($speeds), 2)];
      $move['speed_p95'] = $speeds[min(count($speeds) - 1, (int)floor(count($speeds) * 0.95))];
    }

    if($move['mode_source'] == 'trip') {
      $trip = $move['trip'];
      unset($move['trip']);
      $duration = $move['end'] - $move['start'];
      $straight = $trip['from'] && $trip['to'] ? Geo::distance($trip['from'][0], $trip['from'][1], $trip['to'][0], $trip['to'][1]) : null;
      // With too few points to trace the route, trust what the phone measured
      if($points < 2 * $duration / 300) {
        if($trip['distance'] !== null && $trip['distance'] > 0) {
          $distance = $trip['distance'];
        } elseif($straight !== null) {
          $distance = $straight;
          $move['inferred'] = true;
        }
      }
      if($move['mode'] == 'plane' && $straight !== null)
        $distance = max($distance, $straight);
    } elseif(!$move['inferred'] && $move['speed_p95'] !== null) {
      if($move['speed_p95'] >= 100 || ($move['speed_p95'] >= 70 && $altitude !== null && $altitude >= 3000))
        $move['mode'] = 'plane';
    }
    $move['distance'] = $distance;
  }

  // Index of the first time after $t (or at $t when $inclusive)
  private static function firstAfter(array $times, float $t, bool $inclusive): int {
    $low = 0;
    $high = count($times);
    while($low < $high) {
      $middle = ($low + $high) >> 1;
      if($times[$middle] < $t || (!$inclusive && $times[$middle] == $t))
        $low = $middle + 1;
      else
        $high = $middle;
    }
    return $low;
  }

}
