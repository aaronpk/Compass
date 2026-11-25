<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Stats\ShardProcessor;

// Shows what the stats pipeline makes of one day file, without saving
// anything. The tool for tuning the thresholds in App\Stats\Segmenter.
class StatsExplain extends Command {

  protected $signature = 'stats:explain {database : Database name} {date : UTC date of the day file, Y-m-d}';
  protected $description = 'Show the stops, moves and totals computed for one day file';

  public function handle() {
    $database = DB::table('databases')->where('name', $this->argument('database'))->first();
    if(!$database) {
      $this->error('No such database');
      return 1;
    }
    $started = microtime(true);
    $result = ShardProcessor::forDatabase($database)->compute($this->argument('date'));
    $elapsed = microtime(true) - $started;

    $cities = DB::table('geo_cities')->whereIn('id', array_filter(array_column($result['segments'], 'city_id')))->pluck('name', 'id');
    $this->table(
      ['UTC', 'local date', 'type', 'mode', 'motion', 'km', 'min', 'points', 'p50 km/h', 'p95 km/h', 'where'],
      array_map(fn($s) => [
        substr($s['start_at'], 11, 5).'-'.substr($s['end_at'], 11, 5).($s['clipped_start'] ? ' <' : '').($s['clipped_end'] ? ' >' : ''),
        $s['local_date'],
        $s['type'].($s['inferred'] ? ' (inferred)' : ''),
        $s['mode'] ? $s['mode'].($s['mode_source'] == 'trip' ? ' (trip)' : '') : '',
        $s['motion'],
        $s['type'] == 'move' ? number_format($s['distance_m'] / 1000, 1) : '',
        round($s['duration_s'] / 60),
        $s['points'],
        $s['speed_p50'] === null ? '' : round($s['speed_p50'] * 3.6),
        $s['speed_p95'] === null ? '' : round($s['speed_p95'] * 3.6),
        $s['type'] == 'stop' ? trim(($cities[$s['city_id']] ?? '').' '.$s['country']) : $s['countries'],
      ], $result['segments'])
    );
    $this->table(['local date', 'ground km', 'flight km', 'moving min', 'stopped min'], array_map(fn($d) => [
      $d['local_date'], number_format($d['distance_m'] / 1000, 1), number_format($d['flight_distance_m'] / 1000, 1),
      round($d['moving_s'] / 60), round($d['stopped_s'] / 60),
    ], $result['days']));
    $this->line(sprintf('%d lines in the file, %d points used, %.0f ms', $result['ledger']['points_total'], $result['ledger']['points_used'], $elapsed * 1000));
    return 0;
  }

}
