<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB;
use Quartz;
use Log;
use DateTime, DateTimeZone;

class Api extends BaseController
{

  public function account(Request $request) {
    $token = $request->input('token');
    if(!$token)
      return response(json_encode(['error' => 'no token provided']))->header('Content-Type', 'application/json');

    $db = DB::table('databases')->where('write_token','=',$token)->first();
    if(!$db)
      return response(json_encode(['error' => 'invalid token']))->header('Content-Type', 'application/json');
    
    return response(json_encode(['name' => $db->name]))->header('Content-Type', 'application/json');    
  }

  public function query(Request $request) {
    $token = $request->input('token');
    if(!$token)
      return response(json_encode(['error' => 'no token provided']))->header('Content-Type', 'application/json');

    $db = DB::table('databases')->where('read_token','=',$token)->first();
    if(!$db)
      return response(json_encode(['error' => 'invalid token']))->header('Content-Type', 'application/json');

    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'r');

    if($date=$request->input('date')) {
      if($request->input('tz')) {
        $tz = $request->input('tz');
      } else {
        $tz = 'America/Los_Angeles';
      }
      $start = DateTime::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', new DateTimeZone($tz));
      $end = DateTime::createFromFormat('Y-m-d H:i:s', $date.' 23:59:59', new DateTimeZone($tz));
    } else {
      return response(json_encode(['error' => 'no date provided']))->header('Content-Type', 'application/json');
    }

    $results = $qz->queryRange($start, $end);

    $locations = [];

    foreach($results as $id=>$record) {
      $record->date->format('U.u');
      $locations[] = $record->data;
    }

    if($request->input('format') == 'linestring') {

      $response = array(
        'type' => 'LineString',
        'coordinates' => array()
      );
      foreach($locations as $loc) {
        $response['coordinates'][] = $loc->geometry->coordinates;
      }

    } else {
      $response = [
        'locations' => $locations
      ];
    }

    return response(json_encode($response))->header('Content-Type', 'application/json');    
  }

  public function input(Request $request) {
    $token = $request->input('token');
    if(!$token)
      return response(json_encode(['error' => 'no token provided']))->header('Content-Type', 'application/json');

    $db = DB::table('databases')->where('write_token','=',$token)->first();
    if(!$db)
      return response(json_encode(['error' => 'invalid token']))->header('Content-Type', 'application/json');

    if(!is_array($request->input('locations')))
      return response(json_encode(['error' => 'invalid input', 'error_description' => 'parameter "locations" must be an array of GeoJSON data with a "timestamp" property']))->header('Content-Type', 'application/json');

    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'w');

    $num = 0;
    foreach($request->input('locations') as $loc) {
      if(array_key_exists('properties', $loc) && array_key_exists('timestamp', $loc['properties'])) {
        try {
          if(preg_match('/^\d+\.\d+$/', $loc['properties']['timestamp']))
            $date = DateTime::createFromFormat('U.u', $loc['properties']['timestamp']);
          elseif(preg_match('/^\d+$/', $loc['properties']['timestamp']))
            $date = DateTime::createFromFormat('U', $loc['properties']['timestamp']);
          else
            $date = new DateTime($loc['properties']['timestamp']);

          if($date) {
            $num++;
            $qz->add($date, $loc);
          } else {
            Log::warning('Received invalid date: ' . $loc['properties']['timestamp']);
          }
        } catch(Exception $e) {
            Log::warning('Received invalid date: ' . $loc['properties']['timestamp']);
        }
      }
    }

    return response(json_encode(['result' => 'ok', 'saved' => $num]))->header('Content-Type', 'application/json');
  }

}
