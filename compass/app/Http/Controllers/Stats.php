<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Stats\Queries;

// Stats pages built from the derived tables (see App\Stats): the yearly
// dashboard and the world map of countries visited.
class Stats extends BaseController
{

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

  private static function databaseFromReadToken(Request $request) {
    $token = $request->input('token');
    return $token ? DB::table('databases')->where('read_token', '=', $token)->first() : null;
  }

  private static function json($data, int $status = 200) {
    return response(json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), $status)
      ->header('Content-Type', 'application/json');
  }

  private static function menu(string $name): array {
    return ['/map/'.$name => 'Map', '/stats/'.$name => 'Stats', '/stats/'.$name.'/world' => 'World'];
  }

  private static function displayURL() {
    return preg_replace('/(^https?:\/\/|\/$)/', '', session('me'));
  }

  // A year from the URL or query string, or the newest year that has data
  private static function pickYear($year, array $years): int {
    if($year !== null && preg_match('/^(19|20)\d\d$/', (string)$year))
      return (int)$year;
    return $years ? $years[0] : (int)date('Y');
  }

  public function year(Request $request, $name, $year = null) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');

    $queries = new Queries($db->id);
    $stats = $queries->year(self::pickYear($year, $queries->years()));

    return view('stats', [
      'displayURL' => self::displayURL(),
      'database' => $db,
      'menu' => self::menu($name),
      'stats' => $stats,
      'metric' => (bool)$db->metric,
    ]);
  }

  public function yearJson(Request $request) {
    $db = self::databaseFromReadToken($request);
    if(!$db)
      return self::json(['error' => 'invalid token']);
    $queries = new Queries($db->id);
    return self::json($queries->year(self::pickYear($request->input('year'), $queries->years())));
  }

  // Countries visited, for all time or one year
  private static function countries(object $db, $year): array {
    $queries = new Queries($db->id);
    $year = $year !== null && preg_match('/^(19|20)\d\d$/', (string)$year) ? (int)$year : null;
    $countries = $queries->countries($year ? "$year-01-01" : '0000-01-01', $year ? "$year-12-31" : '9999-12-31');
    return [
      'year' => $year,
      'years' => $queries->years(),
      'countries' => $countries,
      'continents' => count(array_unique(array_filter(array_column($countries, 'continent')))),
    ];
  }

  public function world(Request $request, $name) {
    $db = self::database($name);
    if(!$db)
      return redirect('/');
    return view('world', [
      'displayURL' => self::displayURL(),
      'database' => $db,
      'menu' => self::menu($name),
      'world' => self::countries($db, $request->input('year')),
      'isOwner' => $db->created_by == session('user_id'),
      'embedURL' => $db->embed_token ? config('compass.base_url').'embed/world/'.$db->embed_token : null,
    ]);
  }

  public function worldJson(Request $request) {
    $db = self::databaseFromReadToken($request);
    if(!$db)
      return self::json(['error' => 'invalid token']);
    return self::json(self::countries($db, $request->input('year')));
  }

  // Turns the public, embeddable world map on or off. Only the database's creator can.
  public function embedSetting(Request $request, $name) {
    $db = self::database($name);
    if(!$db || $db->created_by != session('user_id'))
      return redirect('/');
    DB::table('databases')->where('id', $db->id)->update([
      'embed_token' => $request->input('embed') == 'enable' ? Str::random(24) : null,
    ]);
    return redirect('/stats/'.$name.'/world');
  }

  // The world map on its own page, for an iframe on another website.
  // The embed token only ever gives access to this list of countries.
  public function embed(Request $request, $token) {
    $db = DB::table('databases')->where('embed_token', '=', $token)->first();
    if(!$db)
      return response('Not found', 404);
    return response(view('world-embed', ['world' => self::countries($db, $request->input('year'))]))
      ->header('Content-Security-Policy', 'frame-ancestors *');
  }

  public function embedJson(Request $request, $token) {
    $db = DB::table('databases')->where('embed_token', '=', $token)->first();
    if(!$db)
      return self::json(['error' => 'not found'], 404);
    return self::json(self::countries($db, $request->input('year')))->header('Access-Control-Allow-Origin', '*');
  }

}
