<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use DB;
use App\Sharing\LocationRequester;
use App\Sharing\Shares;
use App\Stats\PointReader;

class Share
{

  // Longest stretch of history sent to a viewer
  const MAX_HISTORY_DAYS = 7;
  const MAX_HISTORY_POINTS = 5000;

  const DURATIONS = [3600 => '1 hour', 14400 => '4 hours', 86400 => '1 day', 0 => 'Until I stop it'];

  private static function json($data, int $status = 200) {
    return response(json_encode($data), $status)->header('Content-Type', 'application/json');
  }

  // [share, database] for a token, or null if the share is over
  private static function find(?string $token): ?array {
    $share = Shares::active($token);
    if(!$share)
      return null;
    $database = DB::table('databases')->where('id', $share->database_id)->first();
    return $database ? [$share, $database] : null;
  }

  public function view(Request $request, $token) {
    $found = self::find($token);
    if(!$found)
      return view('share-expired');
    [$share, $database] = $found;

    return view('share', [
      'database' => $database,
      'share' => $share,
      'share_token' => $token,
      // With nchan set up, the page listens for new locations instead of asking every few seconds
      'live' => (bool)config('compass.nchan_publish_url'),
    ]);
  }

  public function current_location(Request $request) {
    $found = self::find($request->input('token'));
    if(!$found)
      return self::json(['error' => 'invalid']);
    [$share, $database] = $found;

    return self::json([
      'data' => $database->last_location === null ? null : Shares::reduce(json_decode($database->last_location)),
    ]);
  }

  // The track so far: since the share was created, or since the trip in
  // progress started
  public function history(Request $request) {
    $found = self::find($request->input('token'));
    if(!$found)
      return self::json(['error' => 'invalid']);
    [$share, $database] = $found;

    $start = max(Shares::trackStart($share, $database), time() - self::MAX_HISTORY_DAYS * 86400);
    $reader = new PointReader(config('compass.storage_dir').$database->name);
    $coordinates = [];
    for($day = strtotime(gmdate('Y-m-d', $start).' UTC'); $day <= time(); $day += 86400) {
      $data = $reader->day(gmdate('Y-m-d', $day));
      foreach($data['points'] ?? [] as $point) {
        if($point[PointReader::T] >= $start)
          $coordinates[] = [$point[PointReader::LNG], $point[PointReader::LAT]];
      }
    }
    // A long share at one point per second is more than a browser needs
    if(count($coordinates) > self::MAX_HISTORY_POINTS) {
      $step = (int)ceil(count($coordinates) / self::MAX_HISTORY_POINTS);
      $last = $coordinates[count($coordinates) - 1];
      $coordinates = array_values(array_filter($coordinates, fn($i) => $i % $step == 0, ARRAY_FILTER_USE_KEY));
      $coordinates[] = $last;
    }

    return self::json([
      'linestring' => ['type' => 'LineString', 'coordinates' => $coordinates],
      'start' => gmdate('c', $start),
    ]);
  }

  // nginx asks this before letting a viewer listen to a share's live channel
  public function authorize(Request $request) {
    $share = Shares::active($request->input('token'));
    if(!$share)
      return response('', 403);
    DB::table('shares')->where('id', $share->id)->update(['last_viewed_at' => date('Y-m-d H:i:s')]);
    return response('', 200);
  }

  // nginx calls this when a viewer starts listening
  public function viewerConnected(Request $request, LocationRequester $requester) {
    $found = self::find($request->input('token'));
    if(!$found)
      return response('', 403);
    $requester->request($found[1]);
    return response('', 200);
  }

  ////////////////////////////////////////////////////////////
  // Managing shares in the web app

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

  public function index(Request $request, $name) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');

    $now = date('Y-m-d H:i:s');
    $shares = DB::table('shares')->where('database_id', $db->id)
      ->orderByRaw('(revoked_at IS NULL AND (expires_at IS NULL OR expires_at > ?)) DESC, created_at DESC, id DESC', [$now])
      ->limit(30)->get()
      ->map(function($share) use($now) {
        $share->active = !$share->revoked_at && (!$share->expires_at || $share->expires_at > $now);
        $share->url = config('compass.base_url').'s/'.$share->token;
        return $share;
      });

    return view('shares', [
      'displayURL' => preg_replace('/(^https?:\/\/|\/$)/', '', session('me')),
      'database' => $db,
      'menu' => database_menu($db),
      'shares' => $shares,
      'durations' => self::DURATIONS,
      'live' => (bool)config('compass.nchan_publish_url'),
    ]);
  }

  public function create(Request $request, $name) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    $duration = (int)$request->input('duration');
    if(!array_key_exists($duration, self::DURATIONS))
      $duration = 3600;
    self::store($db->id, $duration, $request->input('label'));
    return redirect('/shares/'.$name);
  }

  public function revoke(Request $request, $name, $id) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    DB::table('shares')->where('id', $id)->where('database_id', $db->id)->whereNull('revoked_at')
      ->update(['revoked_at' => date('Y-m-d H:i:s')]);
    return redirect('/shares/'.$name);
  }

  // Creates a share and returns its token. A duration of 0 means until stopped.
  public static function store(int $databaseId, int $seconds, $label = null): string {
    $token = Str::random(15);
    DB::table('shares')->insert([
      'database_id' => $databaseId,
      'created_at' => date('Y-m-d H:i:s'),
      'expires_at' => $seconds > 0 ? date('Y-m-d H:i:s', time() + $seconds) : null,
      'token' => $token,
      'label' => is_string($label) && trim($label) !== '' ? mb_substr(trim($label), 0, 100) : null,
    ]);
    return $token;
  }

}
