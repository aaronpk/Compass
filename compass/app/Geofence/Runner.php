<?php
namespace App\Geofence;

use App\Stats\Geo\CountryIndex;
use App\Stats\Geo\DbCellStore;
use App\Stats\Geo\MysqlCityIndex;
use App\Stats\Geo\PlaceResolver;
use App\Stats\PointReader;
use Illuminate\Support\Facades\DB;

// Runs a database's fences over newly arrived points, and saves the new
// state and the events.
//
// The points come straight from the request that delivered them. Anything at
// or before the newest point already seen is ignored, so a batch handed in
// twice, or old data arriving late, changes nothing.
class Runner {

  // An event found this long after it happened is marked as delayed
  const DELAYED_SECONDS = 300;

  // $points are [time, lat, lng, accuracy] lists in any order (see point()).
  // Returns the ids of the deliveries (geofence_deliveries) waiting to be sent.
  public static function run(int $databaseId, array $points, ?float $now = null): array {
    $now ??= microtime(true);
    $lock = 'compass-geofence-'.$databaseId;
    // Two batches of points must not be worked through at the same time
    if(!DB::selectOne('SELECT GET_LOCK(?, 5) AS locked', [$lock])->locked)
      return [];
    try {
      return self::locked($databaseId, $points, $now);
    } finally {
      DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
    }
  }

  // A stored GeoJSON location as [time, lat, lng, accuracy], or null if it
  // isn't a usable position. The same rules as PointReader.
  public static function point(float $time, array $location): ?array {
    $properties = $location['properties'] ?? [];
    if(isset($properties['type']) || isset($properties['action']))
      return null;
    $coordinates = $location['geometry']['coordinates'] ?? null;
    if(!is_array($coordinates) || !isset($coordinates[0], $coordinates[1]) || !is_numeric($coordinates[0]) || !is_numeric($coordinates[1]))
      return null;
    $lng = (float)$coordinates[0];
    $lat = (float)$coordinates[1];
    if(($lat == 0 && $lng == 0) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180)
      return null;
    $accuracy = isset($properties['horizontal_accuracy']) && is_numeric($properties['horizontal_accuracy']) ? (float)$properties['horizontal_accuracy'] : null;
    if($accuracy !== null && ($accuracy > PointReader::MAX_ACCURACY || $accuracy < 0))
      return null;
    return [$time, $lat, $lng, $accuracy];
  }

  // The cursor for a database that has just got its first fence: start from
  // now, so the fence doesn't fire for things that already happened
  public static function start(int $databaseId): void {
    DB::table('databases')->where('id', $databaseId)->whereNull('geofence_cursor')
      ->update(['geofence_cursor' => json_encode(['seen' => microtime(true), 'last' => null, 'rejected' => null])]);
  }

  private static function locked(int $databaseId, array $points, float $now): array {
    $database = DB::table('databases')->where('id', $databaseId)->first();
    if(!$database)
      return [];
    $rows = DB::table('geofences')->where('database_id', $databaseId)->whereNull('deleted_at')->get()->keyBy('id');
    if(!count($rows))
      return [];

    $cursor = $database->geofence_cursor ? json_decode($database->geofence_cursor, true) : null;
    if(!is_array($cursor) || !isset($cursor['seen'])) {
      self::start($databaseId);
      return [];
    }

    usort($points, fn($a, $b) => $a[0] <=> $b[0]);
    // The rest of the tuple (speed, altitude, motion) isn't used here
    $points = array_map(fn($p) => [$p[0], $p[1], $p[2], $p[3], null, null, null], $points);

    [$accepted, $newCursor] = Evaluator::accept($points, $cursor, $now);
    $previous = $cursor['last'] ?? null;

    $fences = $fenceStates = $watches = $watchStates = [];
    foreach($rows as $id => $row) {
      $pending = $row->candidate ? json_decode($row->candidate, true) : [];
      $since = $row->state_since ? (float)strtotime($row->state_since.' UTC') : null;
      if($row->kind == 'place') {
        $watches[$id] = $row->place_level;
        $watchStates[$id] = ['value' => $row->value, 'label' => $row->value_label, 'since' => $since, 'candidate' => $pending['candidate'] ?? null];
      } elseif($fence = Shapes::fromRow($row)) {
        $fences[$id] = $fence;
        $fenceStates[$id] = ['state' => $row->state, 'since' => $since, 'candidate' => $pending['candidate'] ?? null, 'crossed' => $pending['crossed'] ?? null];
      }
    }

    [$fenceStates, $events] = Evaluator::run($fences, $fenceStates, $accepted, $previous);
    [$watchStates, $changes] = (new PlaceWatcher(self::lookup()))->run($watches, $watchStates, $accepted, $previous);
    $events = array_merge($events, $changes);
    usort($events, fn($a, $b) => $a['t'] <=> $b['t']);

    $date = fn(?float $t) => $t === null ? null : gmdate('Y-m-d H:i:s', (int)$t);
    $send = [];
    DB::transaction(function() use($databaseId, $rows, $fenceStates, $watchStates, $events, $newCursor, $now, $date, &$send) {
      foreach($fenceStates as $id => $state) {
        $pending = array_filter(['candidate' => $state['candidate'], 'crossed' => $state['crossed']], fn($v) => $v !== null);
        DB::table('geofences')->where('id', $id)->update([
          'state' => $state['state'],
          'state_since' => $date($state['since']),
          'candidate' => $pending ? json_encode($pending) : null,
        ]);
      }
      foreach($watchStates as $id => $state) {
        DB::table('geofences')->where('id', $id)->update([
          'value' => $state['value'],
          'value_label' => $state['label'] === null ? null : mb_substr($state['label'], 0, 200),
          'state_since' => $date($state['since']),
          'candidate' => $state['candidate'] ? json_encode(['candidate' => $state['candidate']]) : null,
        ]);
      }

      $hooks = $events ? DB::table('geofence_webhooks')->where('database_id', $databaseId)->whereNull('deleted_at')->get()->groupBy('geofence_id') : [];
      foreach($events as $event) {
        $fence = $rows[$event['fence']];
        $age = $now - $event['t'];
        $key = ['geofence_id' => $fence->id, 'type' => $event['type'], 'event_at' => $date($event['t'])];
        $inserted = DB::table('geofence_events')->insertOrIgnore($key + [
          'database_id' => $databaseId,
          'created_at' => date('Y-m-d H:i:s'),
          'lat' => round($event['lat'], 5),
          'lng' => round($event['lng'], 5),
          'accuracy' => $event['acc'] === null ? null : (int)round($event['acc']),
          'from_value' => $event['from'][0] ?? null,
          'from_label' => $event['from'][1] ?? null,
          'to_value' => $event['to'][0] ?? null,
          'to_label' => $event['to'][1] ?? null,
          'delayed' => $age > self::DELAYED_SECONDS,
        ]);
        if(!$inserted)
          continue;
        $eventId = DB::table('geofence_events')->where($key)->value('id');
        // Each of the place's web hooks that asked for this kind of event
        foreach($hooks[$fence->id] ?? [] as $hook) {
          if(!Webhook::wants($hook, $event['type']))
            continue;
          $late = $hook->max_age !== null && $age > $hook->max_age;
          $id = DB::table('geofence_deliveries')->insertGetId(['event_id' => $eventId, 'webhook_id' => $hook->id, 'status' => $late ? 'skipped' : 'pending']);
          if(!$late)
            $send[] = $id;
        }
      }

      DB::table('databases')->where('id', $databaseId)->update(['geofence_cursor' => json_encode($newCursor)]);
    });

    return $send;
  }

  // Works out where the device has been for the last $days days from the
  // stored day files, as if the places had existed all along. It fills in
  // each place's state and since when, and records what happened before the
  // place was made. No web hook is called for any of it.
  //
  // What a place has already recorded is left as it is. Returns counts, or null when the database has no places.
  public static function backfill(object $database, int $days, ?callable $progress = null): ?array {
    $rows = DB::table('geofences')->where('database_id', $database->id)->whereNull('deleted_at')->get()->keyBy('id');
    if(!count($rows))
      return null;

    $fences = $fenceStates = $watches = $watchStates = [];
    foreach($rows as $id => $row) {
      if($row->kind == 'place')
        $watches[$id] = $row->place_level;
      elseif($fence = Shapes::fromRow($row))
        $fences[$id] = $fence;
    }

    $reader = new PointReader(config('compass.storage_dir').$database->name);
    $watcher = new PlaceWatcher(self::lookup());
    $cursor = null;
    $events = [];
    $read = 0;
    $feed = function(string $date) use(&$reader, $watcher, $fences, $watches, &$fenceStates, &$watchStates, &$cursor, &$events, &$read) {
      $day = $reader->day($date);
      if(!$day)
        return;
      [$accepted, $next] = Evaluator::accept($day['points'], $cursor, microtime(true));
      $previous = $cursor['last'] ?? null;
      [$fenceStates, $found] = Evaluator::run($fences, $fenceStates, $accepted, $previous);
      [$watchStates, $changes] = $watcher->run($watches, $watchStates, $accepted, $previous);
      $cursor = $next;
      $read += count($accepted);
      array_push($events, ...$found, ...$changes);
    };

    $today = strtotime(gmdate('Y-m-d').' UTC');
    for($day = $today - $days * 86400; $day <= $today; $day += 86400) {
      $feed(gmdate('Y-m-d', $day));
      if($progress)
        $progress();
    }
    if(!$cursor)
      return ['points' => 0, 'events' => 0, 'places' => 0];

    // The slow part is done. Points that arrived meanwhile are picked up
    // with new points held back, so none falls between this and them.
    $lock = 'compass-geofence-'.$database->id;
    if(!DB::selectOne('SELECT GET_LOCK(?, 30) AS locked', [$lock])->locked)
      throw new \RuntimeException('The places of '.$database->name.' are busy, try again');
    try {
      // A new reader, because the first one remembers the files it has read
      $reader = new PointReader(config('compass.storage_dir').$database->name);
      foreach([$today - 86400, $today, $today + 86400] as $day)
        $feed(gmdate('Y-m-d', $day));
      usort($events, fn($a, $b) => $a['t'] <=> $b['t']);

      $date = fn(?float $t) => $t === null ? null : gmdate('Y-m-d H:i:s', (int)$t);
      $added = 0;
      DB::transaction(function() use($database, $rows, $fenceStates, $watchStates, $events, $cursor, $date, &$added) {
        foreach($fenceStates as $id => $state) {
          $pending = array_filter(['candidate' => $state['candidate'], 'crossed' => $state['crossed']], fn($v) => $v !== null);
          DB::table('geofences')->where('id', $id)->update([
            'state' => $state['state'],
            'state_since' => $date($state['since']),
            'candidate' => $pending ? json_encode($pending) : null,
          ]);
        }
        foreach($watchStates as $id => $state) {
          DB::table('geofences')->where('id', $id)->update([
            'value' => $state['value'],
            'value_label' => $state['label'] === null ? null : mb_substr($state['label'], 0, 200),
            'state_since' => $date($state['since']),
            'candidate' => $state['candidate'] ? json_encode(['candidate' => $state['candidate']]) : null,
          ]);
        }
        // A place's history is filled in up to where it already starts:
        // when the place was made, or its first event if that is earlier
        // (a point that arrived late)
        $first = [];
        foreach(DB::table('geofence_events')->where('database_id', $database->id)->orderByDesc('event_at')->get(['geofence_id', 'type', 'event_at']) as $row)
          $first[$row->geofence_id] = $row;
        $fill = [];
        foreach($events as $event) {
          $fence = $rows[$event['fence']];
          $known = strtotime($fence->created_at.' UTC');
          if(isset($first[$fence->id]))
            $known = min($known, strtotime($first[$fence->id]->event_at.' UTC'));
          if($event['t'] < $known)
            $fill[$fence->id][] = $event;
        }
        foreach($fill as $id => $list) {
          // Arriving twice in a row can't happen. If the last event found
          // is the same as the first one already there, it is that event,
          // seen a little differently.
          $last = end($list);
          if(isset($first[$id]) && in_array($last['type'], ['enter', 'exit']) && $last['type'] == $first[$id]->type)
            array_pop($list);
          foreach($list as $event) {
            $fence = $rows[$id];
            $added += DB::table('geofence_events')->insertOrIgnore([
              'geofence_id' => $fence->id,
              'type' => $event['type'],
              'event_at' => $date($event['t']),
              'database_id' => $database->id,
              'created_at' => date('Y-m-d H:i:s'),
              'lat' => round($event['lat'], 5),
              'lng' => round($event['lng'], 5),
              'accuracy' => $event['acc'] === null ? null : (int)round($event['acc']),
              'from_value' => $event['from'][0] ?? null,
              'from_label' => $event['from'][1] ?? null,
              'to_value' => $event['to'][0] ?? null,
              'to_label' => $event['to'][1] ?? null,
              'delayed' => true,
            ]);
          }
        }
        // Never move the cursor back
        $live = json_decode((string)DB::table('databases')->where('id', $database->id)->value('geofence_cursor'), true);
        $cursor['seen'] = max($cursor['seen'], $live['seen'] ?? 0);
        DB::table('databases')->where('id', $database->id)->update(['geofence_cursor' => json_encode($cursor)]);
      });
    } finally {
      DB::select('SELECT RELEASE_LOCK(?)', [$lock]);
    }
    return ['points' => $read, 'events' => $added, 'places' => count($fenceStates) + count($watchStates)];
  }

  // Country, region and city for a position, each as [code, name] or null,
  // from the lookup tables the stats use, and the nearest airport (see Airports)
  public static function lookup(): callable {
    static $resolver, $airports, $cities = [], $names = [];
    $resolver ??= new PlaceResolver(new CountryIndex, new MysqlCityIndex, new DbCellStore('geo_cells'));
    $airports ??= new Airports;
    return function(float $lat, float $lng) use(&$resolver, &$airports, &$cities, &$names) {
      $place = $resolver->resolve($lat, $lng);
      $country = $place['country'];
      $name = function(string $table, string $code) use(&$names) {
        return $names[$table.$code] ??= DB::table($table)->where('code', $code)->value('name') ?? $code;
      };
      $result = ['country' => $country ? [$country, $name('geo_countries', $country)] : null, 'region' => null, 'city' => null, 'airport' => $airports->at($lat, $lng)];
      if($place['city_id']) {
        $city = $cities[$place['city_id']] ??= DB::table('geo_cities')->where('id', $place['city_id'])->first() ?: false;
        // A city just over a border doesn't say which region this is
        if($city && (!$country || $city->country == $country)) {
          $result['city'] = [(string)$city->id, $city->name];
          if($city->admin1 !== null) {
            $code = $city->country.'.'.$city->admin1;
            $result['region'] = [$code, $name('geo_admin1', $code)];
          }
        }
      }
      return $result;
    };
  }

}
