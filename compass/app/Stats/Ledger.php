<?php
namespace App\Stats;

use Illuminate\Support\Facades\DB;

// The stats_shards table: which day files exist, and which still need processing
class Ledger {

  // Day files that have never been processed, were processed by an older
  // version of the pipeline, or have had data added since
  public static function stale(int $databaseId, ?string $from = null, ?string $to = null) {
    $query = DB::table('stats_shards')->where('database_id', $databaseId)
      ->where(function($q) {
        $q->whereNull('version')->orWhere('version', '<', Version::PIPELINE)->orWhereNotNull('dirty_at');
      });
    if($from)
      $query->where('shard_date', '>=', $from);
    if($to)
      $query->where('shard_date', '<=', $to);
    return $query;
  }

  // Marks day files as needing another pass, adding them if they are new
  public static function markDirty(int $databaseId, array $dates): void {
    if(!$dates)
      return;
    $now = date('Y-m-d H:i:s');
    DB::table('stats_shards')->upsert(
      array_map(fn($date) => ['database_id' => $databaseId, 'shard_date' => $date, 'dirty_at' => $now], array_values(array_unique($dates))),
      ['database_id', 'shard_date'], ['dirty_at']
    );
  }

  // Adds every day file found on disk to the ledger. Returns how many there are.
  public static function register(int $databaseId, string $databaseDir): int {
    $dates = [];
    $thisYear = (int)date('Y') + 1;
    foreach(glob(rtrim($databaseDir, '/').'/[0-9][0-9][0-9][0-9]/[0-9][0-9]/[0-9][0-9].txt') as $file) {
      if(!preg_match('#/(\d{4})/(\d{2})/(\d{2})\.txt$#', $file, $m))
        continue;
      // A bad timestamp from a device can create a file for a year that makes no sense
      if($m[1] < 2000 || $m[1] > $thisYear || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))
        continue;
      $dates[] = "$m[1]-$m[2]-$m[3]";
    }
    foreach(array_chunk($dates, 1000) as $chunk) {
      DB::table('stats_shards')->insertOrIgnore(array_map(fn($date) => ['database_id' => $databaseId, 'shard_date' => $date], $chunk));
    }
    return count($dates);
  }

}
