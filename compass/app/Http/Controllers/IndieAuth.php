<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use GuzzleHttp;
use DB;

class IndieAuth
{
  use IndieAuthSession;

  private function _redirectURI() {
    return config('compass.base_url') . 'auth/callback';
  }

  public function start(Request $request) {
    $me = \IndieAuth\Client::normalizeMeURL($request->input('me'));
    if(!$me) {
      return view('auth/error', ['error' => 'Invalid URL']);
    }

    if(!config('compass.allow_new_users')) {
      $user = DB::table('users')->where('url', $me)->first();
      if(!$user) {
        return view('auth/error', ['error' => 'User Not Registered']);
      }
    }

    $state = \IndieAuth\Client::generateStateParameter();

    if(preg_match('/https?:\/\/github\.com\/[^ \/]+/', $me)) {
      $authorizationURL = 'https://github.com/login/oauth/authorize'
        . '?client_id=' . config('compass.github_id')
        . '&state=' . $state;

      session([
        'auth_state' => $state, 
        'attempted_me' => $me,
      ]);

    } else {
      $this->configureIndieAuthClient($this->_redirectURI());

      list($authorizationURL, $error) = $this->withIndieAuthSession('indieauth', function() use($me) {
        list($url, $error) = \IndieAuth\Client::begin($me);
        // Sites that don't advertise an authorization endpoint sign in through the default one
        if($error && $error['error'] == 'missing_authorization_endpoint' && config('compass.default_auth_endpoint')) {
          list($url, $error) = \IndieAuth\Client::begin($me, false, config('compass.default_auth_endpoint'));
        }
        return [$url, $error];
      });

      if($error) {
        return view('auth/error', ['error' => $error['error_description'] ?: $error['error']]);
      }
    }

    return redirect($authorizationURL);
  }

  public function callback(Request $request) {
    $state = session('indieauth.indieauth_state');
    if(!$state || !session('indieauth.indieauth_entered_url')) {
      return view('auth/error', ['error' => 'Missing state information. Start over.']);
    }

    if($request->input('error')) {
      return view('auth/error', ['error' => $request->input('error')]);
    }

    if($state != $request->input('state')) {
      return view('auth/error', ['error' => 'State did not match. Start over.']);
    }

    $this->configureIndieAuthClient($this->_redirectURI());
    list($response, $error) = $this->withIndieAuthSession('indieauth', function() use($request) {
      return \IndieAuth\Client::complete($request->query());
    });

    // As before, a code that can't be verified just returns to the home page
    if(!$error && isset($response['me'])) {
      session()->flush();
      session(['me' => $response['me']]);
      $this->_userLoggedIn($response['me']);
    }

    return redirect('/');
  }

  public function github(Request $request) {
    if(!session('auth_state') || !session('attempted_me')) {
      return view('auth/error', ['error' => 'Missing state information. Start over.']);
    }

    if($request->input('error')) {
      return view('auth/error', ['error' => $request->input('error')]);
    }

    if(session('auth_state') != $request->input('state')) {
      return view('auth/error', ['error' => 'State did not match. Start over.']);
    }

    if(!$request->input('code')) {
      return view('auth/error', ['error' => 'An unknown error occurred']);
    }

    $client = new GuzzleHttp\Client([
      'http_errors' => false
    ]);
    $res = $client->post('https://github.com/login/oauth/access_token', [
      'form_params' => [
        'client_id' => config('compass.github_id'),
        'client_secret' => config('compass.github_secret'),
        // 'redirect_uri' => config('compass.base_url') . 'auth/github',
        'code' => $request->input('code'),
        'state' => session('auth_state')
      ],
      'headers' => [
        'Accept' => 'application/json'
      ]
    ]);
    if($res->getStatusCode() == 200) {
      $body = $res->getBody();
      $data = json_decode($body);
      if($data) {
        if(property_exists($data, 'access_token')) {

          // Now check the username of the user that just logged in
          $res = $client->get('https://api.github.com/user', [
            'headers' => [
              'Authorization' => 'token ' . $data->access_token
            ]
          ]);
          if($res->getStatusCode() == 200) {
            $data = json_decode($res->getBody());
            if(property_exists($data, 'login')) {
              session()->flush();
              $me = 'https://github.com/' . $data->login;
              session(['me' => $me]);
              $this->_userLoggedIn($me);
              return redirect('/');
            } else {
              return view('auth/error', ['error' => 'Login failed']);
            }
          } else {
            return view('auth/error', ['error' => 'Login failed']);
          }
        } else {
          $err = '';
          if(property_exists($data, 'error_description')) {
            $err = ': ' . $data->error_description;
          }
          return view('auth/error', ['error' => 'Login failed' . $err]);
        }
      } else {
        return view('auth/error', ['error' => 'Error parsing response body from GitHub']);
      }
    } else {
      return view('auth/error', ['error' => 'Could not verify login from GitHub: ' . $res->getBody()]);
    }
  }

  private function _userLoggedIn($url) {
    // Create the user record if it doesn't exist yet
    $user = DB::table('users')->where('url','=',$url)->first();
    if($user) {
      DB::table('users')->where('id', $user->id)->update([
        'last_login' => date('Y-m-d H:i:s')
      ]);
      session(['user_id' => $user->id]);
    } else {
      $user_id = DB::table('users')->insertGetId([
        'url' => $url,
        'created_at' => date('Y-m-d H:i:s'),
        'last_login' => date('Y-m-d H:i:s'),
      ]);
      session(['user_id' => $user_id]);
    }
  }

  public function logout(Request $request) {
    session()->flush();
    return redirect('/');
  }

}
