<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB;
use Quartz;
use Log;
use DateTime, DateTimeZone;
use App\Jobs\TripComplete;

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

    if($request->input('tz')) {
      $tz = $request->input('tz');
    } else {
      $tz = 'America/Los_Angeles';
    }

    if($date=$request->input('date')) {
      $start = DateTime::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', new DateTimeZone($tz));
      $end = DateTime::createFromFormat('Y-m-d H:i:s', $date.' 23:59:59', new DateTimeZone($tz));
    } else {
      return response(json_encode(['error' => 'no date provided']))->header('Content-Type', 'application/json');
    }

    $results = $qz->queryRange($start, $end);

    $locations = [];
    $properties = [];
    $events = [];

    foreach($results as $id=>$record) {
      if(property_exists($record->data->properties, 'action')) {
        $rec = $record->data;
        $date = $record->date;
        $rec->properties->unixtime = (int)$date->format('U');
        $events[] = $rec;
      } else {
        #$record->date->format('U.u');
        $locations[] = $record->data;
        $props = $record->data->properties;
        $date = $record->date;
        $date->setTimeZone(new DateTimeZone($tz));
        $props->timestamp = $date->format('c');
        $props->unixtime = (int)$date->format('U');
        $properties[] = $props;
      }
    }

    if($request->input('format') == 'linestring') {

      $linestring = array(
        'type' => 'LineString',
        'coordinates' => array(),
        'properties' => $properties
      );
      foreach($locations as $loc) {
        if(property_exists($loc, 'geometry'))
          $linestring['coordinates'][] = $loc->geometry->coordinates;
        else
          $linestring['coordinates'][] = null;
      }

      $response = array(
        'linestring' => $linestring,
        'events' => $events
      );

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
    $trips = 0;
    foreach($request->input('locations') as $loc) {
      if(array_key_exists('properties', $loc)) {
        if(array_key_exists('timestamp', $loc['properties'])) {
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

              if(array_key_exists('type', $loc['properties']) && $loc['properties']['type'] == 'trip') {
                try {
                  $job = (new TripComplete($db->id, $loc))->onQueue('compass');
                  $this->dispatch($job);
                  $trips++;
                } catch(Exception $e) {
                  Log::warning('Received invalid trip');
                }
              }

            } else {
              Log::warning('Received invalid date: ' . $loc['properties']['timestamp']);
            }
          } catch(Exception $e) {
              Log::warning('Received invalid date: ' . $loc['properties']['timestamp']);
          }
        }
      }
    }

    return response(json_encode(['result' => 'ok', 'saved' => $num, 'trips' => $trips]))->header('Content-Type', 'application/json');
  }

}
