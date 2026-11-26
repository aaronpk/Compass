<?php
namespace App\Geofence;

use App\Stats\Geo\Geo;
use App\Stats\PointReader as P;

// Decides when a device entered or left a fence. No database, no framework:
// it is handed fences, their state and new points, and returns the new state
// and the events.
//
// Finding out whether a point is inside a fence is the easy part. What takes
// care is not firing on bad data:
//
//  - Accuracy. A point is "inside" only when its accuracy circle is inside
//    the fence, and "outside" only when the whole circle is beyond the edge
//    by a margin. A point in between says nothing and changes nothing.
//  - Margin. Leaving needs to go further out than entering, so a device
//    sitting on the edge doesn't flap.
//  - Confirmation. A change needs a second point agreeing with the first,
//    some seconds later, unless the device reports only now and then.
//  - Order. Points at or before the newest one already seen are ignored, so
//    late data can never move a fence backwards.
//  - Jumps. A point that would need an impossible speed to reach is dropped.
//
// A device that goes quiet inside a fence stays inside. Nothing is invented.
class Evaluator {

  // Assumed when a point doesn't say how accurate it is
  const DEFAULT_ACCURACY = 50;
  // A point less accurate than this, and than the fence is big, is no use
  const MAX_ACCURACY = 200;

  const MARGIN_MIN = 25;
  const MARGIN_MAX = 100;
  const MARGIN_SHARE = 0.2;

  // A change is confirmed by a second agreeing point this long after the first
  const CONFIRM_SECONDS = 10;
  // When the previous point is older than this the device is reporting only
  // now and then, and waiting for a second point could take many minutes.
  // One point is taken at its word.
  const SPARSE_SECONDS = 60;

  // Faster than any airliner
  const MAX_SPEED = 300;

  // A line between two points is trusted to show where the device went only
  // when they are this close in time
  const CROSS_SECONDS = 600;

  // Points further in the future than this have a broken clock
  const FUTURE_SECONDS = 300;

  // Picks the points worth evaluating out of a time-sorted list.
  //
  // $cursor is what was remembered from last time, or null:
  //   ['seen' => time of the newest point looked at,
  //    'last' => [t, lat, lng, accuracy] of the last point accepted,
  //    'rejected' => the same for a point just dropped as a jump]
  // Returns [accepted points, new cursor].
  public static function accept(array $points, ?array $cursor, float $now): array {
    $seen = $cursor['seen'] ?? 0;
    $last = $cursor['last'] ?? null;
    $rejected = $cursor['rejected'] ?? null;
    $accepted = [];
    foreach($points as $point) {
      $t = $point[P::T];
      if($t <= $seen || $t > $now + self::FUTURE_SECONDS)
        continue;
      $seen = $t;
      $mark = [$t, $point[P::LAT], $point[P::LNG], $point[P::ACC]];
      if($last && self::jump($last, $mark)) {
        // If the point agrees with the one dropped before it, it was the
        // earlier accepted point that was wrong. Carry on from here.
        if(!$rejected || self::jump($rejected, $mark)) {
          $rejected = $mark;
          continue;
        }
      }
      $accepted[] = $point;
      $last = $mark;
      $rejected = null;
    }
    return [$accepted, ['seen' => $seen, 'last' => $last, 'rejected' => $rejected]];
  }

  // $fences: id => fence array (see Shapes)
  // $states: id => ['state' => unknown|outside|inside, 'since' => time or null,
  //                 'candidate' => null or ['to', 't', 'lat', 'lng', 'acc', 'n'],
  //                 'crossed' => time of the last cross event or null]
  // $points: accepted points, oldest first
  // $previous: [t, lat, lng, accuracy] of the accepted point before them, or null
  //
  // Returns [states, events]. An event is
  //   ['fence' => id, 'type' => enter|exit|cross, 't', 'lat', 'lng', 'acc'].
  public static function run(array $fences, array $states, array $points, ?array $previous): array {
    $events = [];
    $sizes = [];
    foreach($fences as $id => $fence) {
      $sizes[$id] = Shapes::size($fence);
      $states[$id] = ($states[$id] ?? []) + ['state' => 'unknown', 'since' => null, 'candidate' => null, 'crossed' => null];
    }

    foreach($points as $point) {
      $t = $point[P::T];
      $lat = $point[P::LAT];
      $lng = $point[P::LNG];
      $accuracy = $point[P::ACC] ?? self::DEFAULT_ACCURACY;
      $sparse = !$previous || $t - $previous[0] >= self::SPARSE_SECONDS;

      foreach($fences as $id => $fence) {
        $state = &$states[$id];
        $size = $sizes[$id];
        $where = self::where($fence, $size, $lat, $lng, $accuracy);
        $event = fn($type, $at, $eLat, $eLng, $eAcc) => ['fence' => $id, 'type' => $type, 't' => $at, 'lat' => $eLat, 'lng' => $eLng, 'acc' => $eAcc];
        $cross = function($at, $eLat, $eLng) use(&$state, &$events, $event) {
          if($state['crossed'] !== null && $at - $state['crossed'] <= self::CROSS_SECONDS)
            return;
          $state['crossed'] = $at;
          $events[] = $event('cross', $at, $eLat, $eLng, null);
        };

        if($where !== null && $where !== $state['state']) {
          $candidate = $state['candidate'];
          if(!$candidate || $candidate['to'] !== $where)
            $candidate = ['to' => $where, 't' => $t, 'lat' => $lat, 'lng' => $lng, 'acc' => $point[P::ACC], 'n' => 0];
          $candidate['n']++;
          $confirmed = ($candidate['n'] >= 2 && $t - $candidate['t'] >= self::CONFIRM_SECONDS)
            || ($candidate['n'] == 1 && $sparse);
          if($confirmed) {
            // The first state a new fence settles into isn't an event
            if($state['state'] != 'unknown')
              $events[] = $event($where == 'inside' ? 'enter' : 'exit', $candidate['t'], $candidate['lat'], $candidate['lng'], $candidate['acc']);
            $state['state'] = $where;
            $state['since'] = $candidate['t'];
            $state['candidate'] = null;
          } else {
            $state['candidate'] = $candidate;
          }
        } elseif($where !== null && $state['candidate']) {
          // Back where it was before the change was confirmed. One point
          // inside and then gone again was a pass through.
          $candidate = $state['candidate'];
          $state['candidate'] = null;
          if($state['state'] == 'outside' && $candidate['to'] == 'inside')
            $cross($candidate['t'], $candidate['lat'], $candidate['lng']);
        } elseif($state['state'] == 'outside' && !$state['candidate'] && $previous
            && $t - $previous[0] <= self::CROSS_SECONDS
            && Shapes::passesThrough($fence, $previous[1], $previous[2], $lat, $lng,
                 min(max($accuracy, $previous[3] ?? self::DEFAULT_ACCURACY), $size / 2))) {
          // No point landed inside, but the line between two of them goes
          // through the fence
          $cross(($previous[0] + $t) / 2, ($previous[1] + $lat) / 2, ($previous[2] + $lng) / 2);
        }
        unset($state);
      }
      $previous = [$t, $lat, $lng, $point[P::ACC]];
    }

    return [$states, $events];
  }

  // 'inside', 'outside', or null when the point can't say
  public static function where(array $fence, float $size, float $lat, float $lng, float $accuracy): ?string {
    if($accuracy > max($size, self::MAX_ACCURACY))
      return null;
    $distance = Shapes::distance($fence, $lat, $lng);
    // Asking for the whole accuracy circle to be inside would make a small
    // fence unreachable for a phone on wifi positioning, so the accuracy
    // counts for at most half the fence's size
    if($distance + min($accuracy, $size / 2) <= 0)
      return 'inside';
    if($distance - $accuracy > self::margin($size))
      return 'outside';
    return null;
  }

  // How far past the edge counts as having left
  public static function margin(float $size): float {
    return max(self::MARGIN_MIN, min(self::MARGIN_MAX, $size * self::MARGIN_SHARE));
  }

  // Would getting from $a to $b need an impossible speed, even allowing for
  // both points being as wrong as they say they might be?
  private static function jump(array $a, array $b): bool {
    $seconds = $b[0] - $a[0];
    if($seconds <= 0)
      return false;
    $metres = Geo::distance($a[1], $a[2], $b[1], $b[2])
      - ($a[3] ?? self::DEFAULT_ACCURACY) - ($b[3] ?? self::DEFAULT_ACCURACY);
    return $metres > self::MAX_SPEED * $seconds;
  }

}
