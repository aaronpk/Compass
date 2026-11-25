<?php
namespace App\Stats;

class Version {
  // Bump this when a change to the pipeline changes its results. Day files
  // processed with an older version are then picked up by
  // "artisan stats:backfill --stale".
  // 2: records the cells touched each day (stats_day_cells)
  const PIPELINE = 2;
}
