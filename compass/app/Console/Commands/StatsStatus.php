<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Stats\Version;

class StatsStatus extends Command {

  protected $signature = 'stats:status';
  protected $description = 'Show how much of each database has been processed for stats';

  public function handle() {
    $rows = DB::table('stats_shards')
      ->join('databases', 'databases.id', '=', 'stats_shards.database_id')
      ->selectRaw('databases.name, COUNT(*) AS files,
        SUM(version = ? AND dirty_at IS NULL) AS done,
        SUM(error IS NOT NULL) AS errors,
        MIN(shard_date) AS first, MAX(shard_date) AS last, MAX(processed_at) AS processed', [Version::PIPELINE])
      ->groupBy('databases.name')->orderBy('databases.name')->get();
    $this->table(['database', 'day files', 'processed', 'waiting', 'errors', 'first day', 'last day', 'last processed'],
      $rows->map(fn($r) => [$r->name, $r->files, $r->done, $r->files - $r->done, $r->errors, $r->first, $r->last, $r->processed])->all());

    $errors = DB::table('stats_shards')->join('databases', 'databases.id', '=', 'stats_shards.database_id')
      ->whereNotNull('error')->orderBy('shard_date', 'desc')->limit(10)->get(['name', 'shard_date', 'error']);
    foreach($errors as $error)
      $this->line(sprintf('  %s %s: %s', $error->name, $error->shard_date, $error->error));
    return 0;
  }

}
