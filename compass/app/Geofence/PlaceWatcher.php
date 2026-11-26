<?php
namespace App\Geofence;

use App\Stats\Geo\Geo;
use App\Stats\PointReader as P;

// Notices when the city, region or country the device is in changes.
// No database: places are looked up through the function it is given.
//
// The lookup returns, for a position,
//   ['country' => [code, name] or null, 'region' => [...], 'city' => [...]]
// A level that comes back null (open sea, countryside far from any listed
// city) says nothing: the device stays in the last place it was known to be.
class PlaceWatcher {

  const LEVELS = ['city', 'region', 'country'];

  const MAX_ACCURACY = 200;
  // Borders are fuzzier than fences: the nearest listed city flips back and
  // forth between two towns, so a change has to hold for longer
  const CONFIRM_SECONDS = 120;
  const SPARSE_SECONDS = 60;
  // Looking places up for every point of a long batch would be wasteful
  const SAMPLE_SECONDS = 15;

  private $lookup;
  private array $memory = [];

  public function __construct(callable $lookup) {
    $this->lookup = $lookup;
  }

  // $watches: id => level
  // $states: id => ['value' => .., 'label' => .., 'since' => time or null,
  //                 'candidate' => null or ['to', 'label', 't', 'lat', 'lng', 'acc', 'n']]
  // Returns [states, events]; an event is ['fence', 'type' => 'change', 't',
  // 'lat', 'lng', 'acc', 'from' => [value, label], 'to' => [value, label]].
  public function run(array $watches, array $states, array $points, ?array $previous): array {
    $events = [];
    foreach($watches as $id => $level)
      $states[$id] = ($states[$id] ?? []) + ['value' => null, 'label' => null, 'since' => null, 'candidate' => null];
    if(!$watches)
      return [$states, $events];

    $sampled = null;
    foreach($points as $point) {
      $t = $point[P::T];
      $sparse = !$previous || $t - $previous[0] >= self::SPARSE_SECONDS;
      $previous = [$t, $point[P::LAT], $point[P::LNG], $point[P::ACC]];
      if(($point[P::ACC] ?? 0) > self::MAX_ACCURACY)
        continue;
      if($sampled !== null && $t - $sampled < self::SAMPLE_SECONDS)
        continue;
      $sampled = $t;
      $place = $this->place($point[P::LAT], $point[P::LNG]);

      foreach($watches as $id => $level) {
        $now = $place[$level] ?? null;
        if(!$now)
          continue;
        $state = &$states[$id];
        if((string)$now[0] === (string)$state['value']) {
          $state['candidate'] = null;
          unset($state);
          continue;
        }
        $candidate = $state['candidate'];
        if(!$candidate || (string)$candidate['to'] !== (string)$now[0])
          $candidate = ['to' => (string)$now[0], 'label' => $now[1], 't' => $t, 'lat' => $point[P::LAT], 'lng' => $point[P::LNG], 'acc' => $point[P::ACC], 'n' => 0];
        $candidate['n']++;
        $confirmed = ($candidate['n'] >= 2 && $t - $candidate['t'] >= self::CONFIRM_SECONDS)
          || ($candidate['n'] == 1 && $sparse);
        if($confirmed) {
          // Finding out where the device is for the first time isn't a change
          if($state['value'] !== null) {
            $events[] = [
              'fence' => $id, 'type' => 'change', 't' => $candidate['t'],
              'lat' => $candidate['lat'], 'lng' => $candidate['lng'], 'acc' => $candidate['acc'],
              'from' => [$state['value'], $state['label']], 'to' => [$candidate['to'], $candidate['label']],
            ];
          }
          $state['value'] = $candidate['to'];
          $state['label'] = $candidate['label'];
          $state['since'] = $candidate['t'];
          $state['candidate'] = null;
        } else {
          $state['candidate'] = $candidate;
        }
        unset($state);
      }
    }
    return [$states, $events];
  }

  // One lookup per cell of about 150 m
  private function place(float $lat, float $lng): array {
    $cell = Geo::geohash($lat, $lng, 7);
    if(!isset($this->memory[$cell])) {
      if(count($this->memory) > 5000)
        $this->memory = [];
      $this->memory[$cell] = ($this->lookup)($lat, $lng);
    }
    return $this->memory[$cell];
  }

}
