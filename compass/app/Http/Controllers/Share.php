<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB;

class Share extends BaseController
{

  private function _databaseFromToken($token) {
    $share = DB::table('shares')
      ->where('token', $token)
      ->where('expires_at', '>', time())
      ->first();

    if(!$share) return false;

    $database = DB::table('databases')->where('id', $share->database_id)->first();

    return $database;
  }

  public function view(Request $request, $token) {
    $database = $this->_databaseFromToken($token);

    if(!$database) {
      return view('share-expired');
    }

    return view('share', [
      'database' => $database,
      'share_token' => $token,
    ]);
  }

  public function current_location(Request $request) {
    $database = $this->_databaseFromToken($request->input('token'));

    $response = [
      'data' => json_decode($database->last_location),
    ];

    return response(json_encode($response))->header('Content-Type', 'application/json');
  }

}
