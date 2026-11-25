<?php
namespace App\Stats;

class Version {
  // Bump this when a change to the pipeline changes its results. Day files
  // processed with an older version are then picked up by
  // "artisan stats:backfill --stale".
  const PIPELINE = 1;
}
