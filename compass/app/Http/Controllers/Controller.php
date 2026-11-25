<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use DB;

class Controller
{
  use IndieAuthSession;

  private static function displayURL() {
    return preg_replace('/(^https?:\/\/|\/$)/', '', session('me'));
  }

  public function index(Request $request) {
    if(session('user_id')) {

      $databases = DB::select('SELECT d.*
        FROM `databases` d
        JOIN database_users u ON d.id = u.database_id
        WHERE u.user_id = ?
        ORDER BY name', [session('user_id')]);

      return view('dashboard', [
        'displayURL' => self::displayURL(),
        'databases' => $databases
      ]);
    } else {
      return view('index');
    }
  }

  public function createDatabase(Request $request) {
    if(!session('user_id'))
      return redirect('/');

    if($request->input('name') == '') {
      $request->session()->flash('create-error', 'Enter a name.');
      return redirect('/');
    }

    // Only alphanumeric chars are allowed
    if(preg_replace('/[^a-zA-Z0-9]/', '', $request->input('name')) != $request->input('name')) {
      $request->session()->flash('create-error', 'Only alphanumeric characters are allowed.');
      $request->session()->flash('database-name', preg_replace('/[^a-zA-Z0-9]/','',$request->input('name')));
      return redirect('/');
    }

    // Check for conflicts
    $db = DB::select('SELECT * FROM `databases` WHERE name = ?', [$request->input('name')]);
    if(count($db) == 0) {

      // Create the database records
      $id = DB::table('databases')->insertGetId([
        'name' => $request->input('name'),
        'read_token' => \Illuminate\Support\Str::random(40),
        'write_token' => \Illuminate\Support\Str::random(40),
        'created_by' => session('user_id'),
        'created_at' => date('Y-m-d H:i:s')
      ]);
      DB::table('database_users')->insert([
        'database_id' => $id,
        'user_id' => session('user_id'),
        'created_at' => date('Y-m-d H:i:s')
      ]);
      return redirect('/');

    } else {
      $request->session()->flash('create-error', 'That database name is already in use.');
      $request->session()->flash('database-name', $request->input('name'));
      return redirect('/');
    }
  }

  public function map(Request $request, $name) {
    if(!session('user_id'))
      return redirect('/');

    // Verify this user has access to the database
    $db = DB::table('databases')
      ->join('database_users', function($join){
        $join->on('databases.id','=','database_users.database_id');
      })
      ->where('user_id','=',session('user_id'))
      ->where('name','=',$name)
      ->first();
    if(!$db)
      return redirect('/');

    // The calendar starts at the earliest year with stats, which can be before
    // the database was created here if older data was imported
    $years = (new \App\Stats\Queries($db->id))->years();

    return view('map', [
      'displayURL' => self::displayURL(),
      'database' => $db,
      'first_stats_year' => $years ? min($years) : null,
      'menu' => [
        '/stats/'.$name => 'Stats',
        '/stats/'.$name.'/world' => 'World',
        '/shares/'.$name => 'Share',
        '/settings/'.$name => 'Settings'
      ],
      'range_from' => $request->input('from') ?: '',
      'range_to' => $request->input('to') ?: '',
      'range_tz' => $request->input('tz') ?: $db->timezone
    ]);
  }

  public function settings(Request $request, $name) {
    if(!session('user_id'))
      return redirect('/');

    // Only the person that created the database can modify it
    $db = DB::table('databases')
      ->where('created_by','=',session('user_id'))
      ->where('name','=',$name)
      ->first();
    if(!$db)
      return redirect('/');

    $users = DB::select('SELECT u.*
      FROM users u
      JOIN database_users d ON u.id = d.user_id
      WHERE d.database_id = ?
      ORDER BY u.url', [$db->id]);

    return view('settings', [
      'displayURL' => self::displayURL(),
      'database' => $db,
      'users' => $users,
      'menu' => [
        '/map/'.$name => 'Map'
      ]
    ]);
  }

  public function updateSettings(Request $request, $name) {
    if(!session('user_id'))
      return redirect('/');

    // Only the person that created the database can modify it
    $db = DB::table('databases')
      ->where('created_by','=',session('user_id'))
      ->where('name','=',$name)
      ->first();
    if(!$db)
      return redirect('/');

    if($request->input('remove_user')) {

      $user = DB::table('users')->where('url','=',$request->input('remove_user'))->first();
      if($user) {
        DB::table('database_users')->where('database_id','=',$db->id)->where('user_id','=',$user->id)->delete();
      }

      return response(json_encode([
        'result' => 'ok'
      ]))->header('Content-Type', 'application/json');

    } else if($request->input('add_user')) {
      // Find user if it exists already
      $user = DB::table('users')->where('url','=',$request->input('add_user'))->first();
      if($user) {
        $user_id = $user->id;
      } else {
        $user_id = DB::table('users')->insertGetId([
          'url' => $request->input('add_user'),
          'created_at' => date('Y-m-d H:i:s')
        ]);
      }

      // Add access to the database
      $exists = DB::table('database_users')->where('database_id','=',$db->id)->where('user_id','=',$user_id)->first();
      if(!$exists) {
        DB::table('database_users')->insert([
          'database_id' => $db->id,
          'user_id' => $user_id,
          'created_at' => date('Y-m-d H:i:s')
        ]);
      }

      return redirect('/settings/'.$db->name);
    } else if($request->input('micropub_endpoint')) {
      DB::table('databases')->where('id', $db->id)
        ->update([
          'micropub_endpoint' => $request->input('micropub_endpoint'),
          'micropub_token' => $request->input('micropub_token'),
        ]);

      return redirect('/settings/'.$db->name);
    } else if($request->input('ping_urls')) {
      DB::table('databases')->where('id', $db->id)
        ->update([
          'ping_urls' => $request->input('ping_urls'),
        ]);

      return redirect('/settings/'.$db->name);
    } else if($request->input('timezone')) {
      DB::table('databases')->where('id', $db->id)
	->update([
	  'timezone' => $request->input('timezone'),
          'metric' => $request->input('metric'),
        ]);

      return redirect('/settings/'.$db->name);
    }
  }

    public function micropubStart(Request $request, $dbName) {

        $me = \IndieAuth\Client::normalizeMeURL($request->input('me'));
        if(!$me) {
            return view('auth/error', ['error' => 'Invalid URL']);
        }

        $this->configureIndieAuthClient($this->_databaseRedirectURI($dbName));

        // Keep each database's authorization in its own session key
        list($authorizationURL, $error) = $this->withIndieAuthSession(self::_micropubSessionKey($dbName), function() use($me) {
            return \IndieAuth\Client::begin($me, 'create');
        });

        if($error) {
          if($error['error'] == 'missing_authorization_endpoint')
            return view('auth/error', ['error' => 'No Authorization Endpoint Specified']);
          return view('auth/error', ['error' => $error['error_description'] ?: $error['error']]);
        }

        return redirect($authorizationURL);
    }

    public function micropubCallback(Request $request, $dbName) {

        $sessionKey = self::_micropubSessionKey($dbName);
        $settingsSession = session($sessionKey, []);

        // Start all error checking
        if(empty($settingsSession['indieauth_state']) || empty($settingsSession['indieauth_entered_url'])) {
            return view('auth/error', ['error' => 'Missing state information. Start over.']);
        }

        if($request->input('error')) {
            return view('auth/error', ['error' => $request->input('error')]);
        }

        if($settingsSession['indieauth_state'] != $request->input('state')) {
            return view('auth/error', ['error' => 'State did not match. Start over.']);
        }

        // Verify that the database exists and doesn't have micropub already
        $db = DB::table('databases')
            ->where('name','=',$dbName)
            ->first();

        if (!$db) {
            return view('auth/error', ['error' => 'Database requested does not exist']);
        }

        if (!empty($db->micropub_token)) {
            return view('auth/error', ['error' => 'Database already is connected to a micropub endpoint. Please remove the existing endpoint first.']);
        }

        if (empty($settingsSession['indieauth_token_endpoint'])) {
            return view('auth/error', ['error' => 'Could not find user\'s token endpoint']);
        }

        $this->configureIndieAuthClient($this->_databaseRedirectURI($dbName));
        list($response, $error) = $this->withIndieAuthSession($sessionKey, function() use($request) {
            return \IndieAuth\Client::complete($request->query());
        });
        $token = $error ? false : ($response['response'] ?? []) + ['me' => $response['me'] ?? null];

        if($token && !empty($token['me'])) {
            // forget the current db settings session
            session()->forget($sessionKey);

            if (!array_key_exists('access_token', $token)) {
                return view('auth/error', ['error' => 'Could not find access_token']);
            }

            if (!array_key_exists('scope', $token) || strpos($token['scope'], 'create') === false) {
                return view('auth/error', ['error' => 'You were not granted a create scope']);
            }

            $micropubEndpoint = \IndieAuth\Client::discoverMicropubEndpoint($token['me']);
            $micropubToken = $token['access_token'];

            DB::table('databases')->where('id', $db->id)
                ->update([
                    'micropub_endpoint' => $micropubEndpoint,
                    'micropub_token' => $micropubToken
                ]);
        } else {
            return view('auth/error', ['error' => 'No url id found']);
        }

        return redirect('/settings/'.$db->name);
    }

    public function removeMicropub(Request $request, $dbName) {

        $db = DB::table('databases')
            ->where('name','=',$dbName)
            ->first();

        if (!$db) {
            return view('auth/error', ['error' => 'Database requested does not exist']);
        }

        DB::table('databases')->where('id', $db->id)
            ->update([
                'micropub_endpoint' => '',
                'micropub_token' => ''
            ]);

        return redirect('/settings/'.$db->name);
    }

    private static function _micropubSessionKey($dbName) {
        return 'micropub_auth_'.$dbName;
    }

    private function _databaseRedirectURI($dbName) {
        return config('compass.base_url') . 'settings/' . $dbName . '/auth/callback';
    }

}
