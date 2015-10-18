<?php
namespace App\Jobs;

use DB;
use Log;
use Quartz;
use p3k\Multipart;
use App\Jobs\Job;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;

class TripComplete extends Job implements SelfHandling, ShouldQueue
{
  private $_dbid;
  private $_data;
  
  public function __construct($dbid, $data) {
    $this->_dbid = $dbid;
    $this->_data = $data;
  }
  
  public function handle() {
    print_r($this->_data);
    
    $db = DB::table('databases')->where('id','=',$this->_dbid)->first();
    
    if(!$db->micropub_endpoint) {
      Log::info('No micropub endpoint configured for database ' . $db->name);
      return;
    }
    
    $qz = new Quartz\DB(env('STORAGE_DIR').$db->name, 'r');
    
    // Build the GeoJSON for this trip
    $geojson = [
      'type' => 'FeatureCollection',
      'features' => [
        [
          'type' => 'Feature',
          'geometry' => $this->_data['geometry'],
          'properties' => []
        ]
      ]
    ];
    $file_path = tempnam(sys_get_temp_dir(), 'compass');
    file_put_contents($file_path, json_encode($geojson));

    // Reverse geocode the start and end location to get an h-adr
    $startAdr = [
      'type' => 'h-adr',
      'properties' => [
        'latitude' => $this->_data['geometry']['coordinates'][1],
        'longitude' => $this->_data['geometry']['coordinates'][0],
      ]
    ];
    $endAdr = [
      'type' => 'h-adr',
      'properties' => [
        'latitude' => $this->_data['geometry']['coordinates'][1],
        'longitude' => $this->_data['geometry']['coordinates'][0],
      ]
    ];

    $distance = 10;
    $duration = 100;

    $params = [
      'h' => 'entry',
      'created' => $this->_data['properties']['end'],
      'published' => $this->_data['properties']['end'],
      'route' => [
        'type' => 'h-route',
        'properties' => [
          'activity' => $this->_data['properties']['mode'],
          'start-location' => $startAdr,
          'end-location' => $endAdr,
          'distance' => [
            'type' => 'h-measure',
            'properties' => [
              'num' => $distance,
              'unit' => 'meter'
            ]
          ],
          'duration' => [
            'type' => 'h-measure',
            'properties' => [
              'num' => $duration,
              'unit' => 'second'
            ]
          ],
          // TODO: avgpace
          // TODO: avgspeed
        ]
      ]
    ];
    
    $multipart = new Multipart();
    $multipart->addArray($params);
    $multipart->addFile('geojson', $file_path, 'application/json');

    $httpheaders = [
      'Authorization: Bearer ' . $db->micropub_token,
      'Content-type: ' . $multipart->contentType()
    ];
    
    // Post to the Micropub endpoint
    $ch = curl_init($db->micropub_endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $httpheaders);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $multipart->data());
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);    
    
    echo "========\n";
    echo $response."\n========\n";

    echo "\n";    
  }
}
