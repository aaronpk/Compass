<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Jobs\Stats\ProcessShards;
use App\Stats\Ledger;

// Builds the stats tables from the day files. Safe to run again at any time:
// only day files that are new, changed, or were processed by an older version
// of the pipeline are done.
class StatsBackfill extends Command {

  protected $signature = 'stats:backfill
    {database? : Database name; all databases if not given}
    {--from= : First UTC date to process, Y-m-d}
    {--to= : Last UTC date to process, Y-m-d}
    {--all : Process every day file again, not just those that need it}
    {--sync : Process here and show progress, instead of queueing jobs}';
  protected $description = 'Compute stats for existing location data';

  public function handle() {
    $databases = DB::table('databases')->orderBy('id');
    if($this->argument('database'))
      $databases->where('name', $this->argument('database'));
    $databases = $databases->get();
    if(!count($databases)) {
      $this->error('No such database');
      return 1;
    }

    foreach($databases as $database) {
      $files = Ledger::register($database->id, config('compass.storage_dir').$database->name);
      if($this->option('all')) {
        $all = DB::table('stats_shards')->where('database_id', $database->id);
        if($this->option('from')) $all->where('shard_date', '>=', $this->option('from'));
        if($this->option('to')) $all->where('shard_date', '<=', $this->option('to'));
        $all->update(['dirty_at' => date('Y-m-d H:i:s')]);
      }
      $waiting = Ledger::stale($database->id, $this->option('from'), $this->option('to'))->count();
      $this->line(sprintf('%s: %d day files, %d to process', $database->name, $files, $waiting));
      if(!$waiting)
        continue;

      if($this->option('sync')) {
        $bar = $this->output->createProgressBar($waiting);
        ProcessShards::run($database, $this->option('from'), $this->option('to'), null, fn() => $bar->advance());
        $bar->finish();
        $this->newLine();
      } else {
        dispatch((new ProcessShards($database->id, $this->option('from'), $this->option('to')))->onQueue('compass-backfill'));
      }
    }
    if(!$this->option('sync'))
      $this->line('Queued. A worker must be listening on the compass-backfill queue (see deploy/compass-stats@.service).');
    return 0;
  }

}
