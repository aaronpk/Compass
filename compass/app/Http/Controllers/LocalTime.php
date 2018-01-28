<?php
namespace App\Http\Controllers;

use Laravel\Lumen\Routing\Controller as BaseController;
use Illuminate\Http\Request;
use DB, Log;
use Quartz;
use DateTime, DateTimeZone, DateInterval;
use App\Jobs\TripComplete;

class LocalTime extends BaseController
{

  public function find(Request $request) {
    $token = $request->input('token');
    if(!$token)
      return response(json_encode(['error' => 'no token provided']))->header('Content-Type', 'application/json');

    $db = DB::table('databases')->where('read_token','=',$token)->first();
    if(!$db)
      return response(json_encode(['error' => 'invalid token']))->header('Content-Type', 'application/json');

    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'r');

    $timezones = [
      '-23:00','-22:00','-21:00','-20:00',
      '-19:00','-18:00','-17:00','-16:00','-15:00','-14:00','-13:00','-12:00','-11:00','-10:00',
      '-09:00','-08:00','-07:00','-06:00','-05:00','-04:00','-03:00','-02:00','-01:00','+00:00',
      '+01:00','+02:00','+03:00','+04:00','+05:00','+06:00','+07:00','+08:00','+09:00','+10:00',
      '+11:00','+12:00','+13:00','+14:00','+15:00','+16:00','+17:00','+18:00','+19:00','+20:00',
      '+21:00','+22:00','+23:00',
    ];
    
    $date = false;
    if($input=$request->input('input')) {
      // Strict input format
      if(preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/', $input)) {
        $date = $input;
      } else {
        // Invalid format
      }
    }
    if(!$date) {
      return response(json_encode(['error' => 'invalid date provided']))->header('Content-Type', 'application/json');
    }

    $candidates = [];
    
    foreach($timezones as $tz) {
      // Interpret the input date in each timezone
      $date = DateTime::createFromFormat('Y-m-d H:i:s', $input, new DateTimeZone($tz));

      // Find the closest record to that absolute timestamp
      if($record = $this->_find_closest_record($qz, $date)) {
        if($record->data) {
          $local = $this->_timezone_for_location($record->data->geometry->coordinates[1], $record->data->geometry->coordinates[0], $record->date->format('c'));
          
          $diff = strtotime($local->localtime) - strtotime($date->format('c'));
  
          // Find the record where the localized timezone offset matches the candidate offset
          if($tz == $local->date->format('P')) {
            $candidates[] = [
              'record' => $record->data,
              'local' => $local,
              'tz' => $tz,
              'diff' => $diff
            ];
          }
        }
      }
    }
    
    // Choose the candidate with the smallest absolute time difference
    usort($candidates, function($a, $b){ 
      return abs($a['diff']) < abs($b['diff']) ? -1 : 1;
    });

    if(count($candidates)) {
      $record = $candidates[0];
      
      $response = [
        'data' => $record['record'],
        'timezone' => [
          'offset' => $record['local']->offset,
          'seconds' => $record['local']->seconds,
          'localtime' => $record['local']->localtime,
          'name' => $record['local']->name,
        ]
      ];
    } else {
      $response = ['data'=>null];
    }
    
    return response(json_encode($response))->header('Content-Type', 'application/json');
  }
  
  private function _timezone_for_location($lat, $lng, $date) {
    $tz = \p3k\Timezone::timezone_for_location($lat, $lng, $date);
    return new TimezoneResult($tz, $date);
  }
  
  private function _find_closest_record($qz, $date) {
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
      return false;
    }

    // Start iterating through the shard and look for the last line that is before the given date
    $shard->init();
    $record = false;
    foreach($shard as $r) {
      if($r->date > $date)
        break;
      $record = $r;
    }
    return $record;
  }

}

class TimezoneResult {
  public $timezone = null;

  private $_now;
  private $_name;

  public function __construct($timezone, $date=false) {
    if($date)
      $this->_now = new DateTime($date);
    else
      $this->_now = new DateTime();
    $this->_now->setTimeZone(new DateTimeZone($timezone));
    $this->_name = $timezone;
  }

  public function __get($key) {
    switch($key) {
      case 'date':
        return $this->_now;
      case 'offset': 
        return $this->_now->format('P');
      case 'seconds':
        return (int)$this->_now->format('Z');
      case 'localtime':
        return $this->_now->format('c');
      case 'name':
        return $this->_name;
    }
  }

  public function __toString() {
    return $this->_name;
  }
}

