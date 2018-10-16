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

    //////////////////////////////////
    // Web Hooks

    // Don't run the web hooks for manually created trips
    if(isset($this->_data['properties']['end_location'])) {
      $urls = preg_split('/\s+/', $db->ping_urls);

      // Build a trip object that looks like the active trip format from Overland
      $trip = [
        'trip' => [
          'mode' => $this->_data['properties']['mode'],
          'start_location' => $this->_data['properties']['start_location'],
          'end_location' => $this->_data['properties']['end_location'],
          'distance' => $this->_data['properties']['distance'],
          'start' => $this->_data['properties']['start'],
          'end' => $this->_data['properties']['end']
        ]
      ];
      $trip = json_encode($trip, JSON_UNESCAPED_SLASHES);

      foreach($urls as $url) {
        if(trim($url)) {
          $ch = curl_init($url);
          curl_setopt($ch, CURLOPT_POST, true);
          curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer '.$db->read_token,
            'Compass-Url: '.env('BASE_URL').'api/trip?token='.$db->read_token
          ]);
          curl_setopt($ch, CURLOPT_POSTFIELDS, $trip);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
          curl_exec($ch);
          Log::info("Notifying ".$url." of a completed trip");
        }
      }
    }

    //////////////////////////////////
    // Micropub

    if(!$db->micropub_endpoint) {
      Log::info('No micropub endpoint configured for database ' . $db->name);
      return;
    }

    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'r');

    // Load the data from the start and end times
    $start = new DateTime($this->_data['properties']['start']);
    $end = new DateTime($this->_data['properties']['end']);

    if($end->format('U') - $start->format('U') < 15) {
      Log::info("Skipping trip since it was too short");
      return;
    }

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
      if(!array_key_exists('start_location', $this->_data['properties'])) {
        $this->_data['properties']['start_location'] = json_decode(json_encode($features[0]), true);
      }
      if(!array_key_exists('end_location', $this->_data['properties'])) {
        $this->_data['properties']['end_location'] = json_decode(json_encode($features[count($features)-1]), true);
      }
    }

    $startAdr = false;
    if(array_key_exists('start_location', $this->_data['properties'])) {
      // Reverse geocode the start and end location to get an h-adr
      $startAdr = [
        'type' => 'h-adr',
        'properties' => [
          'latitude' => $this->_data['properties']['start_location']['geometry']['coordinates'][1],
          'longitude' => $this->_data['properties']['start_location']['geometry']['coordinates'][0],
        ]
      ];
      Log::info('Looking up start location');
      $start = self::geocode($startAdr['properties']['latitude'], $startAdr['properties']['longitude']);
      if($start && property_exists($start, 'locality')) {
        $startAdr['properties']['locality'] = $start->locality;
        $startAdr['properties']['region'] = $start->region;
        $startAdr['properties']['country'] = $start->country;
        Log::info('Found start: '.$start->full_name.' '.$start->timezone);
      }
    } else {
      $start = false;
    }

    $endAdr = false;
    if(array_key_exists('end_location', $this->_data['properties'])) {
      $endAdr = [
        'type' => 'h-adr',
        'properties' => [
          'latitude' => $this->_data['properties']['end_location']['geometry']['coordinates'][1],
          'longitude' => $this->_data['properties']['end_location']['geometry']['coordinates'][0],
        ]
      ];
      Log::info('Looking up end location');
      $end = self::geocode($endAdr['properties']['latitude'], $endAdr['properties']['longitude']);
      if($end && property_exists($end, 'locality')) {
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
      $simple = \p3k\geo\ramerDouglasPeucker($points, 0.0001);
      $last = false;
      $distance = 0;
      foreach($simple as $p) {
        if($last) {
          $distance += \p3k\geo\gc_distance($p[1], $p[0], $last[1], $last[0]);
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

    // TODO: avgpace for runs
    // TODO: avgspeed for bike rides
    // TODO: avg heart rate if available


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


}
