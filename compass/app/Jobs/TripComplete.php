<?php
namespace App\Jobs;

use DB;
use Log;
use Quartz;
use p3k\Multipart;
use App\Jobs\Job;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use DateTime, DateTimeZone;

class TripComplete extends Job implements SelfHandling, ShouldQueue
{
  private $_dbid;
  private $_data;

  public function __construct($dbid, $data) {
    $this->_dbid = $dbid;
    $this->_data = $data;
  }

  public function handle() {
    // echo "Job Data\n";
    // echo json_encode($this->_data)."\n";
    if(!is_array($this->_data)) return;

    $db = DB::table('databases')->where('id','=',$this->_dbid)->first();

    Log::info("Starting job for ".$db->name);

    Log::debug(json_encode($this->_data));

    if(!$db->micropub_endpoint) {
      Log::info('No micropub endpoint configured for database ' . $db->name);
      return;
    }

    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'r');

    // Load the data from the start and end times
    $start = new DateTime($this->_data['properties']['start']);
    $end = new DateTime($this->_data['properties']['end']);
    $results = $qz->queryRange($start, $end);
    $features = [];
    foreach($results as $id=>$record) {
      // Don't include app action tracking data
      if(!property_exists($record->data->properties, 'action')) {
	    // Ignore locations with accuracy worse than 5000m
	    if(property_exists($record->data->properties, 'horizontal_accuracy') && $record->data->properties->horizontal_accuracy <= 5000) {
	      $record->data->properties = array_filter((array)$record->data->properties, function($k){
	        // Remove some of the app-specific tracking keys from each record
	        return !in_array($k, ['locations_in_payload','desired_accuracy','significant_change','pauses','deferred']);
	      }, ARRAY_FILTER_USE_KEY);
	      $features[] = $record->data;
	    }
      }
    }

    // Build the GeoJSON for this trip
    $geojson = [
      'type' => 'FeatureCollection',
      'features' => $features
    ];
    $file_path = tempnam(sys_get_temp_dir(), 'compass');
    file_put_contents($file_path, json_encode($geojson));

    // If there are no start/end coordinates in the request, use the first and last coordinates
    if(count($features)) {
      if(!array_key_exists('start-coordinates', $this->_data['properties'])) {
        $this->_data['properties']['start-coordinates'] = $features[0]->geometry->coordinates;
      }
      if(!array_key_exists('end-coordinates', $this->_data['properties'])) {
        $this->_data['properties']['end-coordinates'] = $features[count($features)-1]->geometry->coordinates;
      }
    }

    $startAdr = false;
    if(array_key_exists('start-coordinates', $this->_data['properties'])) {
      // Reverse geocode the start and end location to get an h-adr
      $startAdr = [
        'type' => 'h-adr',
        'properties' => [
          'latitude' => $this->_data['properties']['start-coordinates'][1],
          'longitude' => $this->_data['properties']['start-coordinates'][0],
        ]
      ];
      Log::info('Looking up start location');
      $start = self::geocode($this->_data['properties']['start-coordinates'][1], $this->_data['properties']['start-coordinates'][0]);
      if($start) {
        $startAdr['properties']['locality'] = $start->locality;
        $startAdr['properties']['region'] = $start->region;
        $startAdr['properties']['country'] = $start->country;
        Log::info('Found start: '.$start->full_name.' '.$start->timezone);
      }
    } else {
      $start = false;
    }

    $endAdr = false;
    if(array_key_exists('end-coordinates', $this->_data['properties'])) {
      $endAdr = [
        'type' => 'h-adr',
        'properties' => [
          'latitude' => $this->_data['properties']['end-coordinates'][1],
          'longitude' => $this->_data['properties']['end-coordinates'][0],
        ]
      ];
      Log::info('Looking up end location');
      $end = self::geocode($this->_data['properties']['end-coordinates'][1], $this->_data['properties']['end-coordinates'][0]);
      if($end) {
        $endAdr['properties']['locality'] = $end->locality;
        $endAdr['properties']['region'] = $end->region;
        $endAdr['properties']['country'] = $end->country;
        Log::info('Found end: '.$end->full_name.' '.$end->timezone);
      }
    } else {
      $end = false;
    }

    // Set the timezone of the dates based on the location
    $startDate = new DateTime($this->_data['properties']['start']);
    if($start && $start->timezone) {
      $startDate->setTimeZone(new DateTimeZone($start->timezone));
    }

    $endDate = new DateTime($this->_data['properties']['end']);
    if($end && $end->timezone) {
      $endDate->setTimeZone(new DateTimeZone($end->timezone));
    }

    if($endDate->format('U') - $startDate->format('U') < 15) {
      Log::info("Skipping trip since it was too short");
      return;
    }

    $params = [
      'h' => 'entry',
      'published' => $endDate->format('c'),
      'trip' => [
        'type' => 'h-trip',
        'properties' => [
          'mode-of-transport' => $this->_data['properties']['mode'],
          'start' => $startDate->format('c'),
          'end' => $endDate->format('c'),
          'route' => 'route.json'
          // TODO: avgpace for runs
          // TODO: avgspeed for bike rides
          // TODO: avg heart rate if available
        ]
      ]
    ];

    if($startAdr) {
      $params['trip']['properties']['start-location'] = $startAdr;
    }
    if($endAdr) {
      $params['trip']['properties']['end-location'] = $endAdr;
    }
    if(array_key_exists('distance', $this->_data['properties'])) {
      $params['trip']['properties']['distance'] = [
        'type' => 'h-measure',
        'properties' => [
          'num' => round($this->_data['properties']['distance']),
          'unit' => 'meter'
        ]
      ];
    }
    if(array_key_exists('duration', $this->_data['properties'])) {
      $params['trip']['properties']['duration'] = [
        'type' => 'h-measure',
        'properties' => [
          'num' => round($this->_data['properties']['duration']),
          'unit' => 'second'
        ]
      ];
    }
    if(array_key_exists('cost', $this->_data['properties'])) {
      $params['trip']['properties']['cost'] = [
        'type' => 'h-measure',
        'properties' => [
          'num' => round($this->_data['properties']['cost'], 2),
          'unit' => 'USD'
        ]
      ];
    }

    // If there is trip data, recalculate the distance and duration based on the actual data
    if(count($features)) {
      $startTime = strtotime($features[0]->properties['timestamp']);
      $endTime = strtotime($features[count($features)-1]->properties['timestamp']);
      $duration = $endTime - $startTime;
      $params['trip']['properties']['duration']['type'] = 'h-measure';
      $params['trip']['properties']['duration']['properties']['num'] = $duration;
      $params['trip']['properties']['duration']['properties']['unit'] = 'second';
      Log::debug("Overriding duration to $duration");

      $points = array_map(function($f){
        return $f->geometry->coordinates;
      }, $features);
      $simple = $this->_ramerDouglasPeucker($points, 0.0001);
      $last = false;
      $distance = 0;
      foreach($simple as $p) {
        if($last) {
          $distance += $this->_gc_distance($p[1], $p[0], $last[1], $last[0]);
        }
        $last = $p;
      }
      if($distance) {
        $params['trip']['properties']['distance']['type'] = 'h-measure';
        $params['trip']['properties']['distance']['properties']['num'] = $distance;
        $params['trip']['properties']['distance']['properties']['unit'] = 'meter';
        Log::debug("Overriding distance to $distance");
      }
    }

    // echo "Micropub Params\n";
    // print_r($params);

    $multipart = new Multipart();
    $multipart->addArray($params);
    $multipart->addFile('route.json', $file_path, 'application/json');

    $httpheaders = [
      'Authorization: Bearer ' . $db->micropub_token,
      'Content-type: ' . $multipart->contentType()
    ];

    Log::info('Sending to the Micropub endpoint: '.$db->micropub_endpoint);
    // Post to the Micropub endpoint
    $ch = curl_init($db->micropub_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheaders);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart->data());
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);

    Log::info("Done!");
    if(preg_match('/Location: (.+)/', $response, $match)) {
      Log::info($match[1]);
    }

    // echo "========\n";
    // echo $response."\n========\n";
    //
    // echo "\n";
  }

  public static function geocode($lat, $lng) {
    $ch = curl_init(env('ATLAS_BASE').'api/geocode?latitude='.$lat.'&longitude='.$lng);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response = curl_exec($ch);
    if($response) {
      return json_decode($response);
    }
  }






  // TODO: move this to a library p3k/Geo


  // http://www.loughrigg.org/rdp/

  //The author has placed this work in the Public Domain, thereby relinquishing all copyrights.
  //You may use, modify, republish, sell or give away this work without prior consent.
  //This implementation comes with no warranty or guarantee of fitness for any purpose.

  //=========================================================================
  //An implementation of the Ramer-Douglas-Peucker algorithm for reducing
  //the number of points on a polyline
  //see http://en.wikipedia.org/wiki/Ramer%E2%80%93Douglas%E2%80%93Peucker_algorithm
  //=========================================================================

  //Finds the perpendicular distance from a point to a straight line.
  //The coordinates of the point are specified as $ptX and $ptY.
  //The line passes through points l1 and l2, specified respectively with their
  //coordinates $l1x and $l1y, and $l2x and $l2y
  public function _perpendicularDistance($ptX, $ptY, $l1x, $l1y, $l2x, $l2y)
  {
      $result = 0;
      if ($l2x == $l1x)
      {
          //vertical lines - treat this case specially to avoid divide by zero
          $result = abs($ptX - $l2x);
      }
      else
      {
          $slope = (($l2y-$l1y) / ($l2x-$l1x));
          $passThroughY = (0-$l1x)*$slope + $l1y;
          $result = (abs(($slope * $ptX) - $ptY + $passThroughY)) / (sqrt($slope*$slope + 1));
      }
      return $result;
  }

  //RamerDouglasPeucker
  //Reduces the number of points on a polyline by removing those that are closer to the line
  //than the distance $epsilon.
  //The polyline is provided as an array of arrays, where each internal array is one point on the polyline,
  //specified by easting (x-coordinate) with key "0" and northing (y-coordinate) with key "1".
  //It is assumed that the coordinates and distance $epsilon are given in the same units.
  //The result is returned as an array in a similar format.
  //Each point returned in the result array will retain all its original data, including its E and N
  //values along with any others.
  public function _ramerDouglasPeucker($pointList, $epsilon)
  {
      if(count($pointList) == 0)
        return array();

      // Find the point with the maximum distance
      $dmax = 0;
      $index = 0;
      $totalPoints = count($pointList);
      for ($i = 1; $i < ($totalPoints - 1); $i++)
      {
          $d = $this->_perpendicularDistance($pointList[$i][0], $pointList[$i][1],
                                     $pointList[0][0], $pointList[0][1],
                                     $pointList[$totalPoints-1][0], $pointList[$totalPoints-1][1]);

          if ($d > $dmax)
          {
              $index = $i;
              $dmax = $d;
          }
      }

      $resultList = array();

      // If max distance is greater than epsilon, recursively simplify
      if ($dmax >= $epsilon)
      {
          // Recursive call
          $recResults1 = $this->_ramerDouglasPeucker(array_slice($pointList, 0, $index + 1), $epsilon);
          $recResults2 = $this->_ramerDouglasPeucker(array_slice($pointList, $index, $totalPoints - $index), $epsilon);

          // Build the result list
          $resultList = array_merge(array_slice($recResults1, 0, count($recResults1) - 1),
                                    array_slice($recResults2, 0, count($recResults2)));
      }
      else
      {
          $resultList = array($pointList[0], $pointList[$totalPoints-1]);
      }
      // Return the result
      return $resultList;
  }

  function _gc_distance($lat1, $lng1, $lat2, $lng2) {
    return ( 6378100 * acos( cos( deg2rad($lat1) ) * cos( deg2rad($lat2) ) * cos( deg2rad($lng2) - deg2rad($lng1) ) + sin( deg2rad($lat1) ) * sin( deg2rad($lat2) ) ) );
  }


}
