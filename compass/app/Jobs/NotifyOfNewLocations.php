<?php
namespace App\Jobs;

use Log;
use App\Jobs\Job;
use Illuminate\Contracts\Bus\SelfHandling;
use Illuminate\Contracts\Queue\ShouldQueue;
use DateTime, DateTimeZone;

class NotifyOfNewLocations extends Job implements SelfHandling, ShouldQueue
{
  private $_dbid;

  public function __construct($dbid, $data) {
    $this->_dbid = $dbid;
  }

  public function handle() {
    $db = DB::table('databases')->where('id','=',$this->_dbid)->first();
    $urls = preg_split('/\s+/', $db->ping_urls);
    foreach($urls as $url) {
      $ch = curl_init($url);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'url' => env('BASE_URL').'api/last?token='.$db->token.'&geocode=1'
      ]));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_exec($ch);
    }
  }
}
