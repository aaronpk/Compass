<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Geofence\Runner;

// Works out from the stored locations where the device has been, so a new
// place knows how long it has been inside or outside, and its history shows
// the arrivals and departures from before it was made. Calls no web hooks.
// Safe to run again.
class PlacesBackfill extends Command {

  protected $signature = 'places:backfill
    {database? : Database name; all databases if not given}
    {--days=30 : How many days back to read}';
  protected $description = 'Fill in the state and history of places from existing location data';

  public function handle() {
    $days = (int)$this->option('days');
    if($days < 1 || $days > 3660) {
      $this->error('--days must be between 1 and 3660');
      return 1;
    }
    $databases = DB::table('databases')->orderBy('id');
    if($this->argument('database'))
      $databases->where('name', $this->argument('database'));
    $databases = $databases->get();
    if(!count($databases)) {
      $this->error('No such database');
      return 1;
    }

    foreach($databases as $database) {
      $result = Runner::backfill($database, $days);
      if($result === null)
        $this->line($database->name.': no places');
      else
        $this->line(sprintf('%s: %d points read, %d places updated, %d past events added', $database->name, $result['points'], $result['places'], $result['events']));
    }
    return 0;
  }

}
