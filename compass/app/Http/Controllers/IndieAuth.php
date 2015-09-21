<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;

class IndieAuth extends BaseController
{
  private function _redirectURI() {
    return env('BASE_URL') . 'auth/callback';
  }

  public function start(Request $request) {
    $me = \IndieAuth\Client::normalizeMeURL($request->input('me'));
    if(!$me) {
      return view('auth/error', ['error' => 'Invalid URL']);
    }

    $authorizationEndpoint = \IndieAuth\Client::discoverAuthorizationEndpoint($me);
    $tokenEndpoint = \IndieAuth\Client::discoverTokenEndpoint($me);

    $state = \IndieAuth\Client::generateStateParameter();
    session([
      'auth_state' => $state, 
      'attempted_me' => $me,
      'authorization_endpoint' => $authorizationEndpoint,
      'token_endpoint' => $tokenEndpoint
    ]);

    // If the user specified only an authorization endpoint, use that
    if(!$authorizationEndpoint) {
      // Otherwise, fall back to indieauth.com
      $authorizationEndpoint = env('DEFAULT_AUTH_ENDPOINT');
    }
    $authorizationURL = \IndieAuth\Client::buildAuthorizationURL($authorizationEndpoint, $me, $this->_redirectURI(), env('BASE_URL'), $state);

    return redirect($authorizationURL);
  }

  public function callback(Request $request) {
    if(!session('auth_state') || !session('attempted_me')) {
      return view('auth/error', ['error' => 'Missing state information. Start over.']);
    }

    if($request->input('error')) {
      return view('auth/error', ['error' => $request->input('error')]);
    }

    if(session('auth_state') != $request->input('state')) {
      return view('auth/error', ['error' => 'State did not match. Start over.']);
    }

    $tokenEndpoint = false;
    if(session('token_endpoint')) {
      $tokenEndpoint = session('token_endpoint');
    } else if(session('authorization_endpoint')) {
      $authorizationEndpoint = session('authorization_endpoint');
    } else {
      $authorizationEndpoint = env('DEFAULT_AUTH_ENDPOINT');
    }
    if($tokenEndpoint) {
      $token = \IndieAuth\Client::getAccessToken($tokenEndpoint, $request->input('code'), session('attempted_me'), $this->_redirectURI(), env('BASE_URL'), $request->input('state'));
    } else {
      $token = \IndieAuth\Client::verifyIndieAuthCode($authorizationEndpoint, $request->input('code'), session('attempted_me'), $this->_redirectURI(), env('BASE_URL'), $request->input('state'));
    }

    if($token && array_key_exists('me', $token)) {
      session()->flush();
      session(['me' => $token['me']]);
    }

    return redirect('/');
  }

  public function logout(Request $request) {
    session()->flush();
    return redirect('/');
  }

}
