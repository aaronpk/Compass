<?php

namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB, Log, Cache;
use Quartz;
use DateTime, DateTimeZone, DateInterval;
use App\Jobs\TripComplete;
use App\Jobs\NotifyOfNewLocations;

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
      $tz = 'UTC';
    }

    if($date=$request->input('date')) {
      $start = DateTime::createFromFormat('Y-m-d H:i:s', $date.' 00:00:00', new DateTimeZone($tz));
      $end = DateTime::createFromFormat('Y-m-d H:i:s', $date.' 23:59:59', new DateTimeZone($tz));
    } elseif(($start=$request->input('start')) && ($end=$request->input('end'))) {
      $start = new DateTime($start, new DateTimeZone($tz));
      $end = new DateTime($end, new DateTimeZone($tz));
    } else {
      return response(json_encode(['error' => 'no date provided']))->header('Content-Type', 'application/json');
    }

    $results = $qz->queryRange($start, $end);

    $locations = [];
    $properties = [];
    $events = [];

    if($request->input('format') == 'linestring') {

      foreach($results as $id=>$record) {
        // When returning a linestring, separate out the "event" records from the "location" records
        if($record->data) {
          if(property_exists($record->data->properties, 'action')) {
            $rec = $record->data;
            # add a unixtime property
            $rec->properties->unixtime = (int)$record->date->format('U');
            $events[] = $rec;
          } else {
            #$record->date->format('U.u');
            // Ignore super inaccurate locations
            if(!property_exists($record->data->properties, 'horizontal_accuracy') 
              || $record->data->properties->horizontal_accuracy <= 5000) {
  	            
              $locations[] = $record->data;
              $props = $record->data->properties;
              $date = $record->date;
              $date->setTimeZone(new DateTimeZone($tz));
              $props->timestamp = $date->format('c');
              $props->unixtime = (int)$date->format('U');
              $properties[] = $props;
            }
          }
        }
      }

      $linestring = array(
        'type' => 'LineString',
        'coordinates' => [],
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
      foreach($results as $id=>$record) {
        if($record->data) {
          $locations[] = $record->data;
        }
      }

      $response = [
        'locations' => $locations
      ];
    }

    return response(json_encode($response))->header('Content-Type', 'application/json');
  }

  public function last(Request $request) {
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
      $tz = 'UTC';
    }

    if($input=$request->input('before')) {
      if(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $input)) {
        // If the input date is given in YYYY-mm-dd HH:mm:ss format, interpret it in the timezone given
        $date = DateTime::createFromFormat('Y-m-d H:i:s', $input, new DateTimeZone($tz));
      } else {
        // Otherwise, parse the string and use the timezone in the input
        $date = new DateTime($input);
        $date->setTimeZone(new DateTimeZone($tz));
      }

      if(!$date) {
        return response(json_encode(['error' => 'invalid date provided']))->header('Content-Type', 'application/json');
      }
    } else {
      $date = new DateTime();
    }

    /* ********************************************** */
    // TODO: move this logic into QuartzDB

    // Load the shard for the given date
    $shard = $qz->shardForDate($date);
    // If the shard doesn't exist, check one day before
    if(!$shard->exists()) {
      $date = $date->sub(new DateInterval('PT86400S'));
      $shard = $qz->shardForDate($date);
    }
    // Now if the shard doesn't exist, return an empty result
    if(!$shard->exists()) {
      return response(json_encode([
        'data'=>null
      ]));
    }

    // Start iterating through the shard and look for the last line that is before the given date
    $shard->init();
    $record = false;
    foreach($shard as $r) {
      if($r->date > $date)
        break;
      $record = $r;
    }
    /* ********************************************** */

    if(!$record) {
      return response(json_encode([
        'data'=>null
      ]));
    }

    $response = [
      'data' => $record->data
    ];

    if($request->input('geocode') && property_exists($record->data, 'geometry') && property_exists($record->data->geometry, 'coordinates')) {
      $coords = $record->data->geometry->coordinates;
      $params = [
        'latitude' => $coords[1],
        'longitude' => $coords[0],
        'date' => $record->data->properties->timestamp
      ];
      $geocode = self::geocode($params);
      if($geocode) {
        $response['geocode'] = $geocode;
      } else {
        $response['geocode'] = null;
      }
    }

    return response(json_encode($response))->header('Content-Type', 'application/json');;
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
    $last_location = false;
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
              $cacheKey = 'compass::'.$db->name.'::'.$date->format('U');

              // Skip adding if the timestamp is already in the cache.
              // Helps prevent writing duplicate data when the HTTP request is interrupted.
              if(!env('CACHE_DRIVER') || !Cache::has($cacheKey)) {
                $num++;
                $qz->add($date, $loc);
                if(env('CACHE_DRIVER'))
                  Cache::put($cacheKey, 1, 360); // cache this for 6 hours
                $last_location = $loc;
              }

              if(array_key_exists('type', $loc['properties']) && $loc['properties']['type'] == 'trip') {
                try {
                  $job = (new TripComplete($db->id, $loc))->onQueue('compass');
                  $this->dispatch($job);
                  $trips++;
                  Log::info('Got a trip record');
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

    if($request->input('current')) {
      $last_location = $request->input('current');
    }

    $response = [
      'result' => 'ok', 
      'saved' => $num, 
      'trips' => $trips
    ];

    if($last_location) {
      /*
      // 2017-08-22 Don't geocode cause it takes too long. Maybe make a separate route for this later.
      $geocode = self::geocode(['latitude'=>$last_location['geometry']['coordinates'][1], 'longitude'=>$last_location['geometry']['coordinates'][0]]);
      $response['geocode'] = [
        'full_name' => $geocode->full_name,
        'locality' => $geocode->locality,
        'country' => $geocode->country
      ];
      */
      $response['geocode'] = null;

      // Notify subscribers that new data is available
      if($db->ping_urls) {
        $job = (new NotifyOfNewLocations($db->id, $last_location))->onQueue('compass');
        $this->dispatch($job);
      }
    }

    return response(json_encode($response))->header('Content-Type', 'application/json');
  }

  public function trip_complete(Request $request) {
    $token = $request->input('token');
    if(!$token)
      return response(json_encode(['error' => 'no token provided']))->header('Content-Type', 'application/json');

    $db = DB::table('databases')->where('write_token','=',$token)->first();
    if(!$db)
      return response(json_encode(['error' => 'invalid token']))->header('Content-Type', 'application/json');

    if($request->input('tz')) {
      $tz = new DateTimeZone($request->input('tz'));
    } else {
      $tz = new DateTimeZone('UTC');
    }
    $start = new DateTime($request->input('start'), $tz);
    $end = new DateTime($request->input('end'), $tz);

    $loc = [
      'properties' => [
        'mode' => $request->input('mode'),
        'start' => $start->format('c'),
        'end' => $end->format('c'),
      ]
    ];

    try {
      $job = (new TripComplete($db->id, $loc))->onQueue('compass');
      $this->dispatch($job);
      Log::info('Got a manual trip record: '.$start->format('c').' '.$end->format('c'));
    } catch(Exception $e) {
      Log::warning('Received invalid trip');
    }

    return response(json_encode(['result' => 'ok']))->header('Content-Type', 'application/json');
  }

  public static function geocode($params) {
    $ch = curl_init(env('ATLAS_BASE').'api/geocode?'.http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($ch);
    if($response) {
      return json_decode($response);
    }
  }

}
