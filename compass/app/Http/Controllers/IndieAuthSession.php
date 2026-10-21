<?php

namespace App\Http\Controllers;

// IndieAuth\Client keeps its state (state, PKCE verifier, endpoints) in
// $_SESSION. Compass uses Laravel's cookie session instead, so copy the
// client's values in from the Laravel session under $key before calling it,
// and save them back afterwards.
trait IndieAuthSession {

  private function withIndieAuthSession($key, callable $callback) {
    $_SESSION = session($key, []);
    try {
      return $callback();
    } finally {
      session([$key => $_SESSION]);
      $_SESSION = [];
    }
  }

  private function configureIndieAuthClient($redirectURL) {
    \IndieAuth\Client::$clientID = config('compass.base_url');
    \IndieAuth\Client::$redirectURL = $redirectURL;
  }

}
