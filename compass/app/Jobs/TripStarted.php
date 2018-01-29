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

class TripStarted extends Job implements SelfHandling, ShouldQueue
{
  private $_dbid;

  public function __construct($dbid) {
    $this->_dbid = $dbid;
  }

  public function handle() {
    $db = DB::table('databases')->where('id','=',$this->_dbid)->first();

    $urls = preg_split('/\s+/', $db->ping_urls);

    $trip = [
      'trip' => json_decode($db->current_trip, true)
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
        Log::info("Notifying ".$url." of a new trip");
      }
    }
  }
}
