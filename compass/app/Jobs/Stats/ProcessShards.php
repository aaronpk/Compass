<?php
namespace App\Jobs\Stats;

use App\Jobs\Job;
use App\Stats\Ledger;
use App\Stats\ShardProcessor;
use App\Stats\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// Processes the day files of one database that need it, oldest first.
// It works for a limited time and then queues itself again for the rest, so a
// long backfill never runs into the queue worker's timeout.
class ProcessShards extends Job {

  const SECONDS = 40;

  public function __construct(
    private int $databaseId,
    private ?string $from = null,
    private ?string $to = null,
  ) {}

  public function handle() {
    $database = DB::table('databases')->where('id', $this->databaseId)->first();
    if(!$database)
      return;

    $remaining = self::run($database, $this->from, $this->to, self::SECONDS);
    if($remaining > 0)
      dispatch((new static($this->databaseId, $this->from, $this->to))->onQueue($this->queue ?: 'compass-backfill'));
  }

  // Processes stale day files until none are left or the time is up.
  // Returns how many are still waiting.
  public static function run(object $database, ?string $from, ?string $to, ?float $seconds, ?callable $progress = null): int {
    $deadline = $seconds === null ? null : microtime(true) + $seconds;
    $processor = ShardProcessor::forDatabase($database);
    while(true) {
      $dates = Ledger::stale($database->id, $from, $to)->orderBy('shard_date')->limit(50)->pluck('shard_date');
      if(!count($dates))
        return 0;
      foreach($dates as $date) {
        if($deadline !== null && microtime(true) >= $deadline)
          return Ledger::stale($database->id, $from, $to)->count();
        try {
          $processor->process($database->id, $date);
        } catch(\Throwable $e) {
          // One unreadable file mustn't stop the rest. Record it and move on.
          Log::error('Stats: '.$database->name.' '.$date.': '.$e->getMessage());
          DB::table('stats_shards')->where('database_id', $database->id)->where('shard_date', $date)
            ->update(['version' => Version::PIPELINE, 'dirty_at' => null, 'processed_at' => date('Y-m-d H:i:s'), 'error' => substr($e->getMessage(), 0, 255)]);
        }
        if($progress)
          $progress($date);
      }
    }
  }

}
