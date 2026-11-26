<?php
namespace App\Geofence;

use App\Stats\Geo\Geo;
use DateTime;
use DateTimeZone;

// Finds the spots where someone spends a lot of time, as candidates for
// places: where they sleep, where they spend their working days, and
// anywhere else they keep coming back to. No database, no framework.
//
// It only suggests. Nothing here creates a place.
//
// The input is the stops the stats pipeline recorded. Those rows are cut at
// UTC midnight, so a night at home is usually two of them; everything is
// counted by how much time overlaps a night or a working day, never by the
// number of rows.
class Suggester {

  // Stops this close to a spot belong to it
  const JOIN_METRES = 100;

  // A night somewhere: this much time there between midnight and 6 am
  const NIGHT_START = 0;
  const NIGHT_END = 6 * 3600;
  const NIGHT_SECONDS = 3 * 3600;
  // Working hours, Monday to Friday
  const WORK_START = 9 * 3600;
  const WORK_END = 17 * 3600;

  // To be suggested a spot needs this many nights...
  const MIN_NIGHTS = 5;
  // ...or this much time, on this many different days, over at least this
  // long. One visit, however long (a weekend away, a week at a conference),
  // is not a place; coming back is what makes it one.
  const MIN_HOURS = 10;
  const MIN_DAYS = 4;
  const MIN_SPAN_DAYS = 14;
  // Weekday working hours before a spot is called work
  const MIN_WORK_HOURS = 20;
  // ...and it can't be somewhere slept at on more than one day in this many
  const WORK_DAYS_PER_NIGHT = 10;

  const MIN_RADIUS = 75;
  const MAX_RADIUS = 300;

  const LIMIT = 20;

  // The grid used to find nearby spots quickly, in degrees. Three cells
  // across is more than JOIN_METRES anywhere people live.
  const CELL = 0.003;

  // $stops: lists of [start, end, lat, lng, timezone name, city id or null],
  //         times as Unix timestamps
  // $fences: existing places as fence arrays (see Shapes)
  // $dismissed: id => [lat, lng] of spots not wanted
  //
  // Returns ['suggestions' => [...], 'dismissed' => [id => spot or null]].
  // A spot is ['kind' => home|work|overnight|frequent, 'lat', 'lng',
  // 'radius', 'hours', 'days', 'nights', 'work_hours', 'span' (days from
  // first to last), 'first', 'last' (Y-m-d), 'city_id'].
  public static function find(array $stops, array $fences = [], array $dismissed = []): array {
    $spots = [];
    foreach(self::cluster($stops) as $cluster) {
      $spot = self::measure($cluster);
      if($spot['nights'] >= self::MIN_NIGHTS
          || ($spot['hours'] >= self::MIN_HOURS && $spot['days'] >= self::MIN_DAYS && $spot['span'] >= self::MIN_SPAN_DAYS))
        $spots[] = $spot;
    }

    // Home is where the most nights are spent; work is where the most
    // weekday working hours are spent, somewhere other than home
    usort($spots, fn($a, $b) => [$b['nights'], $b['hours']] <=> [$a['nights'], $a['hours']]);
    $home = $spots && $spots[0]['nights'] >= self::MIN_NIGHTS ? 0 : null;
    $work = null;
    foreach($spots as $i => $spot) {
      // Somewhere people also sleep is another home (an earlier one, a
      // second one), not a workplace, however many weekdays were spent there
      if($i !== $home && $spot['work_hours'] >= self::MIN_WORK_HOURS && $spot['nights'] * self::WORK_DAYS_PER_NIGHT <= $spot['days']
          && ($work === null || $spot['work_hours'] > $spots[$work]['work_hours']))
        $work = $i;
    }
    foreach($spots as $i => &$spot)
      $spot['kind'] = $i === $home ? 'home' : ($i === $work ? 'work' : ($spot['nights'] >= self::MIN_NIGHTS ? 'overnight' : 'frequent'));
    unset($spot);

    $rank = ['home' => 0, 'work' => 1, 'overnight' => 2, 'frequent' => 2];
    usort($spots, fn($a, $b) => [$rank[$a['kind']], $b['hours']] <=> [$rank[$b['kind']], $a['hours']]);

    $suggestions = [];
    $found = array_fill_keys(array_keys($dismissed), null);
    foreach($spots as $spot) {
      foreach($dismissed as $id => [$lat, $lng]) {
        if(Geo::distance($spot['lat'], $spot['lng'], (float)$lat, (float)$lng) <= self::JOIN_METRES) {
          $found[$id] ??= $spot;
          continue 2;
        }
      }
      foreach($fences as $fence) {
        if(Shapes::distance($fence, $spot['lat'], $spot['lng']) <= 0)
          continue 2;
      }
      if(count($suggestions) < self::LIMIT)
        $suggestions[] = $spot;
    }
    return ['suggestions' => $suggestions, 'dismissed' => $found];
  }

  // Groups stops that are at the same spot. Each cluster is
  // ['lat', 'lng', 'weight' (seconds), 'stops' => [...]].
  private static function cluster(array $stops): array {
    // Longest first, so a spot forms around the stays that define it
    usort($stops, fn($a, $b) => ($b[1] - $b[0]) <=> ($a[1] - $a[0]));

    $clusters = [];
    $grid = [];
    $near = function(float $lat, float $lng, ?int $except = null) use(&$clusters, &$grid): ?int {
      $row = (int)floor($lat / self::CELL);
      $column = (int)floor($lng / self::CELL);
      $best = null;
      $bestDistance = self::JOIN_METRES;
      for($r = $row - 1; $r <= $row + 1; $r++) {
        for($c = $column - 1; $c <= $column + 1; $c++) {
          foreach($grid["$r:$c"] ?? [] as $index) {
            if($index === $except || !isset($clusters[$index]))
              continue;
            $distance = Geo::distance($lat, $lng, $clusters[$index]['lat'], $clusters[$index]['lng']);
            if($distance <= $bestDistance) {
              $best = $index;
              $bestDistance = $distance;
            }
          }
        }
      }
      return $best;
    };

    foreach($stops as $stop) {
      $seconds = $stop[1] - $stop[0];
      if($seconds <= 0)
        continue;
      $index = $near($stop[2], $stop[3]);
      if($index === null) {
        $index = count($clusters);
        $clusters[] = ['lat' => $stop[2], 'lng' => $stop[3], 'weight' => 0, 'stops' => []];
        // Filed under where it started; its centre drifts by far less than a cell
        $grid[(int)floor($stop[2] / self::CELL).':'.(int)floor($stop[3] / self::CELL)][] = $index;
      }
      $cluster = &$clusters[$index];
      $total = $cluster['weight'] + $seconds;
      $cluster['lat'] = ($cluster['lat'] * $cluster['weight'] + $stop[2] * $seconds) / $total;
      $cluster['lng'] = ($cluster['lng'] * $cluster['weight'] + $stop[3] * $seconds) / $total;
      $cluster['weight'] = $total;
      $cluster['stops'][] = $stop;
      unset($cluster);
    }

    // Two spots whose centres drifted to within reach of each other are one
    foreach(array_keys($clusters) as $index) {
      if(!isset($clusters[$index]))
        continue;
      $other = $near($clusters[$index]['lat'], $clusters[$index]['lng'], $index);
      if($other === null)
        continue;
      // The lighter one is folded into the heavier one
      [$keep, $drop] = $clusters[$index]['weight'] >= $clusters[$other]['weight'] ? [$index, $other] : [$other, $index];
      $a = $clusters[$keep];
      $b = $clusters[$drop];
      $total = $a['weight'] + $b['weight'];
      $clusters[$keep] = [
        'lat' => ($a['lat'] * $a['weight'] + $b['lat'] * $b['weight']) / $total,
        'lng' => ($a['lng'] * $a['weight'] + $b['lng'] * $b['weight']) / $total,
        'weight' => $total,
        'stops' => array_merge($a['stops'], $b['stops']),
      ];
      unset($clusters[$drop]);
    }
    return array_values($clusters);
  }

  private static function measure(array $cluster): array {
    static $zones = [];
    $night = [];   // local day number => seconds there in the small hours
    $days = [];    // local day number => true
    $work = 0;
    $cities = [];
    $distances = [];

    foreach($cluster['stops'] as [$start, $end, $lat, $lng, $tz, $city]) {
      $distances[] = Geo::distance($cluster['lat'], $cluster['lng'], $lat, $lng);
      if($city !== null)
        $cities[$city] = ($cities[$city] ?? 0) + ($end - $start);

      // Local clock time, taking the timezone's offset at the start of the
      // stop. A stop is at most a day long, so a clock change inside it
      // moves things by an hour at worst.
      try {
        $zone = $zones[$tz] ??= new DateTimeZone($tz ?: 'UTC');
      } catch(\Exception $e) {
        $zone = $zones[$tz] = new DateTimeZone('UTC');
      }
      $offset = $zone->getOffset(new DateTime('@'.(int)$start));
      $from = $start + $offset;
      $to = $end + $offset;
      for($day = (int)floor($from / 86400); $day <= (int)floor(($to - 1) / 86400); $day++) {
        $midnight = $day * 86400;
        $days[$day] = true;
        $overlap = fn($a, $b) => max(0, min($to, $midnight + $b) - max($from, $midnight + $a));
        $night[$day] = ($night[$day] ?? 0) + $overlap(self::NIGHT_START, self::NIGHT_END);
        // Day 0 (1 January 1970) was a Thursday
        $weekday = (($day % 7) + 7 + 4) % 7;
        if($weekday >= 1 && $weekday <= 5)
          $work += $overlap(self::WORK_START, self::WORK_END);
      }
    }

    sort($distances);
    $spread = $distances[(int)floor(0.9 * (count($distances) - 1))];
    arsort($cities);
    ksort($days);
    $date = fn(int $day) => gmdate('Y-m-d', $day * 86400);

    return [
      'lat' => round($cluster['lat'], 5),
      'lng' => round($cluster['lng'], 5),
      'radius' => (int)max(self::MIN_RADIUS, min(self::MAX_RADIUS, ceil(($spread + 50) / 25) * 25)),
      'hours' => (int)round($cluster['weight'] / 3600),
      'days' => count($days),
      'nights' => count(array_filter($night, fn($seconds) => $seconds >= self::NIGHT_SECONDS)),
      'work_hours' => (int)round($work / 3600),
      // Days from the first visit to the last
      'span' => array_key_last($days) - array_key_first($days) + 1,
      'first' => $date(array_key_first($days)),
      'last' => $date(array_key_last($days)),
      'city_id' => $cities ? (int)array_key_first($cities) : null,
    ];
  }

}
