<?php
namespace App\Jobs;

use DB, Log;
use App\Jobs\Job;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use DateTime, DateTimeZone;

class NotifyOfNewLocations extends Job implements SelfHandling, ShouldQueue
{
  private $_dbid;

  public function __construct($dbid) {
    $this->_dbid = $dbid;
  }

  public function handle() {
    $db = DB::table('databases')->where('id','=',$this->_dbid)->first();
    $urls = preg_split('/\s+/', $db->ping_urls);

    foreach($urls as $url) {
      if(trim($url)) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
          'Content-Type: application/json',
          'Authorization: Bearer '.$db->read_token,
          'Compass-Url: '.env('BASE_URL').'api/last?token='.$db->read_token.'&geocode=1'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $db->last_location);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $timestamp = '';
        if($db->last_location) {
          $timestamp = json_decode($db->last_location)->properties->timestamp;
        }
        Log::info("Notifying ".$url." with current location: ".$timestamp);
      }
    }
  }
}
