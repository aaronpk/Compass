<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;
use App\Geofence\Runner;
use App\Geofence\Shapes;
use App\Geofence\Suggester;
use App\Geofence\Text;
use App\Geofence\Webhook;

// Places: geofences the device can be in or out of. A place stands on its
// own; web hooks are added to it separately, as many as wanted, each for the
// events it chooses.
//
// Every database also has three built-in "areas" that need no drawing: the
// city, region and country the device is in. They are rows of the same table
// (kind "place"), made the first time the Places page is opened, and can be
// given web hooks like any other place but not renamed or deleted.
class Geofences
{

  const MAX_FENCES = 200;
  const MAX_HOOKS = 10;
  const MIN_RADIUS = 50;
  const MAX_RADIUS = 100000;

  // How old an event may be and still have its web hook sent
  const MAX_AGES = ['' => 'However late', 300 => 'Up to 5 minutes late', 900 => 'Up to 15 minutes late', 3600 => 'Up to 1 hour late', 21600 => 'Up to 6 hours late', 86400 => 'Up to 1 day late'];

  // How much history the suggested places are worked out from
  const SPANS = ['3' => 'Last 3 months', '12' => 'Last 12 months', 'all' => 'All time'];

  const LEVELS = ['city' => 'City', 'region' => 'Region', 'country' => 'Country'];

  private static function json($data, int $status = 200) {
    return response(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $status)->header('Content-Type', 'application/json');
  }

  // The database, if the signed-in user is a member of it
  private static function database(string $name) {
    if(!session('user_id'))
      return null;
    return DB::table('databases')
      ->join('database_users', 'databases.id', '=', 'database_users.database_id')
      ->where('user_id', '=', session('user_id'))
      ->where('name', '=', $name)
      ->select('databases.*')
      ->first();
  }

  // Makes the three built-in areas for a database that doesn't have them yet
  private static function ensureAreas(object $db): void {
    DB::transaction(function() use($db) {
      // Two pages opened at once must not both add them
      DB::table('databases')->where('id', $db->id)->lockForUpdate()->first();
      $have = DB::table('geofences')->where('database_id', $db->id)->where('kind', 'place')->whereNull('deleted_at')->pluck('place_level')->all();
      foreach(self::LEVELS as $level => $name) {
        if(!in_array($level, $have))
          DB::table('geofences')->insert(['database_id' => $db->id, 'name' => $name, 'kind' => 'place', 'place_level' => $level, 'created_at' => date('Y-m-d H:i:s')]);
      }
    });
    Runner::start($db->id);
  }

  private static function fences(int $databaseId) {
    return DB::table('geofences')->where('database_id', $databaseId)->whereNull('deleted_at')->orderBy('name')->orderBy('id')->get();
  }

  // What the map and the API are given for a fence
  private static function describe(object $fence): array {
    $out = ['id' => (int)$fence->id, 'name' => $fence->name, 'kind' => $fence->kind];
    if($fence->kind == 'circle') {
      $out += ['lat' => (float)$fence->lat, 'lng' => (float)$fence->lng, 'radius' => (int)$fence->radius];
    } elseif($fence->kind == 'polygon') {
      $out['geometry'] = json_decode((string)$fence->geojson);
    } else {
      $out += ['level' => $fence->place_level, 'value' => $fence->value, 'value_name' => $fence->value_label];
    }
    if($fence->kind != 'place')
      $out['state'] = $fence->state;
    $out['since'] = $fence->state_since ? gmdate('Y-m-d\TH:i:s\Z', strtotime($fence->state_since.' UTC')) : null;
    return $out;
  }

  // Recent events with the place's name and how each web hook went
  private static function events(int $databaseId, ?int $fenceId, int $limit) {
    $query = DB::table('geofence_events')->where('database_id', $databaseId);
    if($fenceId)
      $query->where('geofence_id', $fenceId);
    $events = $query->orderBy('event_at', 'desc')->orderBy('id', 'desc')->limit($limit)->get();
    $deliveries = DB::table('geofence_deliveries')->whereIn('event_id', $events->pluck('id'))->orderBy('id')->get()->groupBy('event_id');
    foreach($events as $event)
      $event->deliveries = $deliveries[$event->id] ?? collect();
    return $events;
  }

  private static function page(object $db, array $more) {
    $last = $db->last_location ? json_decode($db->last_location, true) : null;
    $coordinates = $last['geometry']['coordinates'] ?? null;
    return $more + [
      'displayURL' => preg_replace('/(^https?:\/\/|\/$)/', '', session('me')),
      'database' => $db,
      'menu' => database_menu($db),
      'text' => new Text($db),
      'center' => is_array($coordinates) && isset($coordinates[0], $coordinates[1]) ? [(float)$coordinates[1], (float)$coordinates[0]] : null,
      'levels' => self::LEVELS,
      'message' => session('places_message'),
      'error' => session('places_error'),
    ];
  }

  public function index(Request $request, $name) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');

    self::ensureAreas($db);
    $all = self::fences($db->id);
    $fences = $all->where('kind', '!=', 'place')->values();
    $order = array_keys(self::LEVELS);
    $hooks = DB::table('geofence_webhooks')->where('database_id', $db->id)->whereNull('deleted_at')
      ->selectRaw('geofence_id, COUNT(*) AS n')->groupBy('geofence_id')->pluck('n', 'geofence_id');
    return view('geofences', self::page($db, [
      'fences' => $fences,
      'areas' => $all->where('kind', 'place')->sortBy(fn($f) => array_search($f->place_level, $order))->values(),
      'hooks' => $hooks,
      'shapes' => $fences->map(fn($f) => self::describe($f) + ['url' => '/places/'.$db->name.'/'.$f->id])->all(),
      'events' => self::events($db->id, null, 30),
      'names' => DB::table('geofences')->where('database_id', $db->id)->pluck('name', 'id'),
    ]));
  }

  // One place: its shape, its web hooks and what happened there
  public function show(Request $request, $name, $id) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $fence = self::fence($db, $id);
    if(!$fence)
      return redirect('/places/'.$name);

    return view('geofence', self::page($db, [
      'fence' => $fence,
      'shapes' => [self::describe($fence)],
      'hooks' => DB::table('geofence_webhooks')->where('geofence_id', $fence->id)->whereNull('deleted_at')->orderBy('id')->get(),
      'events' => self::events($db->id, $fence->id, 30),
      'max_ages' => self::MAX_AGES,
      'secret' => Webhook::secret($db),
    ]));
  }

  private static function fence(object $db, $id) {
    return DB::table('geofences')->where('id', $id)->where('database_id', $db->id)->whereNull('deleted_at')->first();
  }

  // The columns a create or update form sets, or a string saying what's wrong
  private static function fields(Request $request, ?object $existing): array|string {
    $kind = $existing ? $existing->kind : $request->input('kind');
    if(!in_array($kind, ['circle', 'polygon']))
      return 'Choose a circle or a shape.';

    $fields = [];
    if(!$existing)
      $fields['kind'] = $kind;

    if($kind == 'circle') {
      $lat = $request->input('lat');
      $lng = $request->input('lng');
      $radius = $request->input('radius');
      if(!is_numeric($lat) || !is_numeric($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0))
        return 'Click the map to choose where the circle goes.';
      if(!is_numeric($radius))
        return 'The radius needs to be a number of metres.';
      $fields += [
        'lat' => round((float)$lat, 5),
        'lng' => round((float)$lng, 5),
        'radius' => (int)max(self::MIN_RADIUS, min(self::MAX_RADIUS, round((float)$radius))),
      ];
    } elseif($kind == 'polygon') {
      $text = trim((string)$request->input('geojson'));
      // Left empty when editing: keep the shape as it is
      if($text !== '' || !$existing) {
        if($text === '')
          return 'Paste the GeoJSON for the shape.';
        try {
          $fields['geojson'] = json_encode(Shapes::parseGeoJSON($text)['geometry']);
        } catch(\InvalidArgumentException $e) {
          return $e->getMessage();
        }
      }
    }

    $name = trim((string)$request->input('name'));
    if($name === '')
      $name = 'Untitled';
    $fields['name'] = mb_substr($name, 0, 100);

    return $fields;
  }

  public function create(Request $request, $name) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    // A place confirmed from the suggestions goes back there for the next one
    $suggested = $request->input('return') == 'suggested';
    $back = redirect($suggested ? self::suggestedUrl($name, $request->input('span')) : '/places/'.$name);

    if(DB::table('geofences')->where('database_id', $db->id)->where('kind', '!=', 'place')->whereNull('deleted_at')->count() >= self::MAX_FENCES)
      return $back->with('places_error', 'A database can have at most '.self::MAX_FENCES.' places.');

    $fields = self::fields($request, null);
    if(is_string($fields))
      return $back->with('places_error', $fields)->withInput();

    $id = DB::table('geofences')->insertGetId($fields + ['database_id' => $db->id, 'created_at' => date('Y-m-d H:i:s')]);
    Runner::start($db->id);
    if($suggested)
      return $back->with('places_message', 'Added "'.$fields['name'].'".')->with('places_added', $id);
    return redirect('/places/'.$name.'/'.$id)
      ->with('places_message', 'Added "'.$fields['name'].'". Compass starts tracking it with the next location your device sends.');
  }

  public function update(Request $request, $name, $id) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $fence = self::fence($db, $id);
    if(!$fence)
      return redirect('/places/'.$name);
    $back = redirect('/places/'.$name.'/'.$fence->id);
    // The built-in areas have nothing to edit
    if($fence->kind == 'place')
      return $back;

    $fields = self::fields($request, $fence);
    if(is_string($fields))
      return $back->with('places_error', $fields);

    // A fence that has moved or changed shape has to find out afresh
    // whether the device is in it, without that counting as an event
    $moved = ($fence->kind == 'circle' && ((float)$fence->lat != $fields['lat'] || (float)$fence->lng != $fields['lng'] || (int)$fence->radius != $fields['radius']))
      || ($fence->kind == 'polygon' && isset($fields['geojson']) && $fields['geojson'] !== $fence->geojson);
    if($moved)
      $fields += ['state' => 'unknown', 'state_since' => null, 'candidate' => null];

    DB::table('geofences')->where('id', $fence->id)->update($fields);
    return $back->with('places_message', 'Saved.');
  }

  public function delete(Request $request, $name, $id) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $fence = self::fence($db, $id);
    // The built-in areas stay
    if($fence && $fence->kind == 'place')
      return redirect('/places/'.$name.'/'.$fence->id);
    if($fence) {
      $now = date('Y-m-d H:i:s');
      DB::table('geofences')->where('id', $fence->id)->update(['deleted_at' => $now]);
      DB::table('geofence_webhooks')->where('geofence_id', $fence->id)->whereNull('deleted_at')->update(['deleted_at' => $now]);
    }
    return redirect('/places/'.$name);
  }

  ////////////////////////////////////////////////////////////
  // Suggested places: spots with a lot of time spent at them, found in the
  // stops the stats pipeline recorded. This only shows them. A place is made
  // when one is confirmed, through create() like any other.

  private static function suggestedUrl(string $name, $span): string {
    return '/places/'.$name.'/suggested'.(isset(self::SPANS[(string)$span]) && (string)$span != '12' ? '?span='.$span : '');
  }

  public function suggested(Request $request, $name) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $span = isset(self::SPANS[(string)$request->input('span')]) ? (string)$request->input('span') : '12';

    $query = DB::table('stats_segments')->where('database_id', $db->id)->where('type', 'stop');
    if($span != 'all')
      $query->where('start_at', '>=', gmdate('Y-m-d H:i:s', strtotime('-'.$span.' months')));
    $stops = [];
    foreach($query->select('start_at', 'end_at', 'start_lat', 'start_lng', 'tz', 'city_id')->orderBy('start_at')->cursor() as $row) {
      $stops[] = [
        (float)strtotime($row->start_at.' UTC'), (float)strtotime($row->end_at.' UTC'),
        (float)$row->start_lat, (float)$row->start_lng, $row->tz, $row->city_id === null ? null : (int)$row->city_id,
      ];
    }

    $fences = self::fences($db->id)->where('kind', '!=', 'place')->values();
    $shapes = $fences->map(fn($f) => Shapes::fromRow($f))->filter()->values()->all();
    $dismissedRows = DB::table('geofence_dismissed')->where('database_id', $db->id)->orderBy('id')->get()->keyBy('id');
    $found = Suggester::find($stops, $shapes, $dismissedRows->map(fn($d) => [(float)$d->lat, (float)$d->lng])->all());

    // Names for the cities the spots are in
    $spots = array_merge($found['suggestions'], array_filter($found['dismissed']));
    $names = DB::table('geo_cities')->whereIn('id', array_values(array_unique(array_filter(array_column($spots, 'city_id')))))->pluck('name', 'id')->all();
    // A spot far from any listed city has no city
    $city = fn(array $spot) => $spot['city_id'] === null ? null : ($names[$spot['city_id']] ?? null);
    $text = new Text($db);
    $number = 0;
    $suggestions = array_map(function($spot) use($city, $text, &$number) {
      $spot['number'] = ++$number;
      $spot['city'] = $city($spot);
      $spot['name'] = $text->suggestedName($spot);
      return $spot;
    }, $found['suggestions']);
    $dismissed = [];
    foreach($dismissedRows as $id => $row) {
      $spot = $found['dismissed'][$id] ?? null;
      if($spot)
        $spot['city'] = $city($spot);
      $dismissed[] = ['id' => (int)$id, 'spot' => $spot, 'name' => $spot ? $text->suggestedName($spot) : null];
    }

    return view('geofences-suggested', self::page($db, [
      'span' => $span,
      'spans' => self::SPANS,
      'suggestions' => $suggestions,
      'dismissed' => $dismissed,
      // No stops at all means the stats were never built, not that there is nothing to suggest
      'no_stats' => !count($stops) && !DB::table('stats_segments')->where('database_id', $db->id)->exists(),
      'shapes' => $fences->map(fn($f) => self::describe($f) + ['url' => '/places/'.$db->name.'/'.$f->id])->all(),
    ]));
  }

  public function dismissSuggestion(Request $request, $name) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $lat = $request->input('lat');
    $lng = $request->input('lng');
    if(is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
      DB::table('geofence_dismissed')->insert([
        'database_id' => $db->id, 'lat' => round((float)$lat, 5), 'lng' => round((float)$lng, 5), 'created_at' => date('Y-m-d H:i:s'),
      ]);
    }
    return redirect(self::suggestedUrl($name, $request->input('span')));
  }

  public function restoreSuggestion(Request $request, $name, $id) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    DB::table('geofence_dismissed')->where('id', $id)->where('database_id', $db->id)->delete();
    return redirect(self::suggestedUrl($name, $request->input('span')));
  }

  ////////////////////////////////////////////////////////////
  // A place's web hooks

  // The columns a web hook form sets, or a string saying what's wrong
  private static function hookFields(Request $request, object $fence): array|string {
    $url = trim((string)$request->input('url'));
    if(!Webhook::validUrl($url))
      return 'The web hook needs an http or https URL.';
    $age = (string)$request->input('max_age');
    $fields = ['url' => $url, 'max_age' => $age !== '' && isset(self::MAX_AGES[(int)$age]) ? (int)$age : null];
    if($fence->kind != 'place') {
      $fields += [
        'on_enter' => (bool)$request->input('on_enter'),
        'on_exit' => (bool)$request->input('on_exit'),
        'on_cross' => (bool)$request->input('on_cross'),
      ];
      if(!$fields['on_enter'] && !$fields['on_exit'] && !$fields['on_cross'])
        return 'Choose at least one event to send the web hook for.';
    }
    return $fields;
  }

  public function createHook(Request $request, $name, $id) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $fence = self::fence($db, $id);
    if(!$fence)
      return redirect('/places/'.$name);
    $back = redirect('/places/'.$name.'/'.$fence->id);

    if(DB::table('geofence_webhooks')->where('geofence_id', $fence->id)->whereNull('deleted_at')->count() >= self::MAX_HOOKS)
      return $back->with('places_error', 'A place can have at most '.self::MAX_HOOKS.' web hooks.');
    $fields = self::hookFields($request, $fence);
    if(is_string($fields))
      return $back->with('places_error', $fields)->withInput();

    DB::table('geofence_webhooks')->insert($fields + ['geofence_id' => $fence->id, 'database_id' => $db->id, 'created_at' => date('Y-m-d H:i:s')]);
    return $back->with('places_message', 'Web hook added.');
  }

  // [fence, hook, redirect back to the place], or a redirect away
  private static function hook(string $name, $id, $hookId) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $fence = self::fence($db, $id);
    $hook = $fence ? DB::table('geofence_webhooks')->where('id', $hookId)->where('geofence_id', $fence->id)->whereNull('deleted_at')->first() : null;
    if(!$hook)
      return redirect('/places/'.$name.($fence ? '/'.$fence->id : ''));
    return [$db, $fence, $hook, redirect('/places/'.$name.'/'.$fence->id)];
  }

  public function updateHook(Request $request, $name, $id, $hookId) {
    $found = self::hook($name, $id, $hookId);
    if(!is_array($found))
      return $found;
    [$db, $fence, $hook, $back] = $found;
    $fields = self::hookFields($request, $fence);
    if(is_string($fields))
      return $back->with('places_error', $fields);
    DB::table('geofence_webhooks')->where('id', $hook->id)->update($fields);
    return $back->with('places_message', 'Web hook saved.');
  }

  public function deleteHook(Request $request, $name, $id, $hookId) {
    $found = self::hook($name, $id, $hookId);
    if(!is_array($found))
      return $found;
    [$db, $fence, $hook, $back] = $found;
    DB::table('geofence_webhooks')->where('id', $hook->id)->update(['deleted_at' => date('Y-m-d H:i:s')]);
    return $back->with('places_message', 'Web hook removed.');
  }

  // Sends a made-up event to the web hook, to try the receiver out
  public function testHook(Request $request, $name, $id, $hookId) {
    $found = self::hook($name, $id, $hookId);
    if(!is_array($found))
      return $found;
    [$db, $fence, $hook, $back] = $found;

    $shape = Shapes::fromRow($fence);
    [$lat, $lng] = $shape ? Shapes::center($shape) : [0.0, 0.0];
    $event = (object)[
      'id' => 0, 'type' => 'test', 'event_at' => gmdate('Y-m-d H:i:s'), 'delayed' => false,
      'lat' => $lat, 'lng' => $lng, 'accuracy' => null,
      'from_value' => null, 'from_label' => null, 'to_value' => $fence->value, 'to_label' => $fence->value_label,
    ];
    $status = Webhook::send($hook->url, Webhook::payload($event, $fence, $db), Webhook::secret($db));
    if($status >= 200 && $status < 300)
      return $back->with('places_message', 'Sent a test event. The receiver answered '.$status.'.');
    return $back->with('places_error', $status
      ? 'The web hook answered '.$status.'. It needs to answer with a 2xx status.'
      : 'The web hook didn\'t answer.');
  }

  ////////////////////////////////////////////////////////////
  // API, with a database's read token

  private static function fromReadToken(Request $request) {
    $token = $request->input('token');
    return $token ? DB::table('databases')->where('read_token', '=', $token)->first() : null;
  }

  // GET /api/geofences?token=
  public function listJson(Request $request) {
    $db = self::fromReadToken($request);
    if(!$db)
      return self::json(['error' => 'invalid token']);
    return self::json(['geofences' => self::fences($db->id)->map(fn($f) => self::describe($f))->all()]);
  }

  // GET /api/geofence-events?token=&since=&limit=
  public function eventsJson(Request $request) {
    $db = self::fromReadToken($request);
    if(!$db)
      return self::json(['error' => 'invalid token']);
    $limit = max(1, min(500, (int)$request->input('limit', 100)));
    $query = DB::table('geofence_events')->where('database_id', $db->id);
    $since = $request->input('since');
    if($since && ($time = strtotime((string)$since)))
      $query->where('event_at', '>', gmdate('Y-m-d H:i:s', $time));
    $fences = DB::table('geofences')->where('database_id', $db->id)->get()->keyBy('id');
    $rows = $query->orderBy('event_at', 'desc')->orderBy('id', 'desc')->limit($limit)->get();
    $deliveries = DB::table('geofence_deliveries')->whereIn('event_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('event_id');
    $events = [];
    foreach($rows as $event) {
      if($fence = $fences[$event->geofence_id] ?? null)
        $events[] = Webhook::payload($event, $fence, $db) + ['web_hooks' => ($deliveries[$event->id] ?? collect())->pluck('status')->all()];
    }
    return self::json(['events' => $events]);
  }

}
