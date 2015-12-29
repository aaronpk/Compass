<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Laravel\Lumen\Routing\DispatchesJobs;
use App\Jobs\TripComplete;
use DB;

class TestTrip extends Command {

  use DispatchesJobs;

  protected $signature = 'test:tripcomplete';
  protected $description = 'Queue a TripComplete job';

  public function handle() {
    $db = DB::table('databases')->where('write_token','=','test')->first();
    $loc = json_decode('{"properties":{"end":"2015-12-28T23:20:14Z","start":"2015-12-28T22:45:22Z","mode":"bicycle","distance":6439.4875686883,"end-coordinates":[-122.67617024493,45.549965919969],"start-coordinates":[-122.63860439893,45.522223161576],"duration":2092.7059409618,"type":"trip","timestamp":"2015-12-28T23:20:14Z"},"stopped_automatically":true,"type":"Feature","geometry":{"type":"Point","coordinates":[-122.67617024493,45.549965919969]}}', true);
    $this->dispatch((new TripComplete($db->id, $loc))->onQueue('compass'));
  }

}
