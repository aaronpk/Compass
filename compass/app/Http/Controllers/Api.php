<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB;
use Quartz;
use Log;
use DateTime;

class Api extends BaseController
{

  public function query(Request $request) {

  }

  public function input(Request $request) {
    $token = $request->input('token');
    if(!$token)
      return response(json_encode(['error' => 'no token provided']))->header('Content-Type', 'application/json');

    $db = DB::table('databases')->where('write_token','=',$token)->first();
    if(!$db)
      return response(json_encode(['error' => 'invalid token']))->header('Content-Type', 'application/json');

    if(!is_array($request->input('locations')))
      return response(json_encode(['error' => 'invalid input', 'error_description' => 'parameter "locations" must be an array of location data with a "timestamp" property']))->header('Content-Type', 'application/json');

    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'w');

    foreach($request->input('locations') as $loc) {
      $date = DateTime::createFromFormat('U', $loc['timestamp']);
      $line = $qz->add($date, $loc);
    }

    return response(json_encode(['result' => 'ok']))->header('Content-Type', 'application/json');
  }

}
