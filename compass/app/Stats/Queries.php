<?php
namespace App\Stats;

use Illuminate\Support\Facades\DB;
use App\Stats\Geo\Geo;

// Reads the stats tables for the stats pages. Everything is per database and
// grouped by local date: the date where the person was.
class Queries {

  // A day counts as active once this much distance was covered, flights included
  const ACTIVE_DAY_METRES = 1000;
  // A city counts for a day once this much time was spent stopped in it
  const CITY_SECONDS = 1200;
  // A day is "away from home" when none of it was spent within this distance of the home city
  const HOME_RADIUS_METRES = 50000;

  public function __construct(private int $databaseId) {}

  // Everything the yearly stats page shows
  public function year(int $year): array {
    $from = "$year-01-01";
    $to = "$year-12-31";
    $days = $this->days($from, $to);
    $countries = $this->countries($from, $to);
    $cities = $this->cities($from, $to);
    $previous = $this->summary($this->days(($year - 1).'-01-01', ($year - 1).'-12-31'));

    return [
      'year' => $year,
      'years' => $this->years(),
      'summary' => $this->summary($days) + [
        'countries' => count($countries),
        'cities' => count($cities),
      ] + $this->away($from, $to, $cities),
      'previous' => $previous + [
        'year' => $year - 1,
        'countries' => count($this->countries(($year - 1).'-01-01', ($year - 1).'-12-31')),
        'cities' => count($this->cities(($year - 1).'-01-01', ($year - 1).'-12-31')),
      ],
      'days' => $days,
      'streaks' => $this->streaks(),
      'months' => $this->months($year, $days),
      'modes' => $this->modes($from, $to),
      'countries' => $countries,
      'top_cities' => array_slice($cities, 0, 10),
      'first_visits' => $this->firstVisits($from, $to),
      'progress' => $this->progress(),
    ];
  }

  // [local date => [distance_m (ground), flight_m, moving_s, stopped_s]]
  public function days(string $from, string $to): array {
    $rows = DB::table('stats_days')
      ->where('database_id', $this->databaseId)->whereBetween('local_date', [$from, $to])
      ->groupBy('local_date')->orderBy('local_date')
      ->selectRaw('local_date, SUM(distance_m) AS distance_m, SUM(flight_distance_m) AS flight_m, SUM(moving_s) AS moving_s, SUM(stopped_s) AS stopped_s')
      ->get();
    $days = [];
    foreach($rows as $row) {
      $days[$row->local_date] = [
        'distance_m' => (int)$row->distance_m, 'flight_m' => (int)$row->flight_m,
        'moving_s' => (int)$row->moving_s, 'stopped_s' => (int)$row->stopped_s,
      ];
    }
    return $days;
  }

  private function summary(array $days): array {
    $ground = array_sum(array_column($days, 'distance_m'));
    $flight = array_sum(array_column($days, 'flight_m'));
    return [
      'distance_m' => $ground + $flight,
      'ground_m' => $ground,
      'flight_m' => $flight,
      'active_days' => count(array_filter($days, [self::class, 'isActive'])),
      'days_with_data' => count($days),
    ];
  }

  private static function isActive(array $day): bool {
    return $day['distance_m'] + $day['flight_m'] >= self::ACTIVE_DAY_METRES;
  }

  // Countries with any time on the ground, most days first
  public function countries(string $from, string $to): array {
    return DB::table('stats_day_places AS p')
      ->leftJoin('geo_countries AS c', 'c.code', '=', 'p.country')
      ->where('p.database_id', $this->databaseId)->whereBetween('p.local_date', [$from, $to])->where('p.country', '<>', '')
      ->groupBy('p.country', 'c.name', 'c.continent')
      ->orderByRaw('days DESC, p.country')
      ->selectRaw('p.country AS code, c.name, c.continent, COUNT(DISTINCT p.local_date) AS days, MIN(p.local_date) AS first, MAX(p.local_date) AS last')
      ->get()->map(fn($row) => (array)$row)->all();
  }

  // One row per local date and city where enough time was spent stopped
  private function cityDays() {
    return DB::table('stats_day_places')
      ->where('database_id', $this->databaseId)->where('city_id', '>', 0)
      ->groupBy('local_date', 'city_id')
      ->havingRaw('SUM(stopped_s) >= ?', [self::CITY_SECONDS])
      ->selectRaw('local_date, city_id, SUM(stopped_s) AS stopped_s');
  }

  // Cities by number of days, most first
  public function cities(string $from, string $to): array {
    return DB::query()->fromSub($this->cityDays()->whereBetween('local_date', [$from, $to]), 'd')
      ->join('geo_cities AS c', 'c.id', '=', 'd.city_id')
      ->groupBy('d.city_id', 'c.name', 'c.country', 'c.admin1', 'c.lat', 'c.lng')
      ->orderByRaw('days DESC, hours DESC')
      ->selectRaw('d.city_id AS id, c.name, c.country, c.admin1, c.lat, c.lng, COUNT(*) AS days, ROUND(SUM(d.stopped_s) / 3600) AS hours')
      ->get()->map(fn($row) => (array)$row)->all();
  }

  // Days spent entirely away from the city the most days were spent in
  private function away(string $from, string $to, array $cities): array {
    if(!$cities)
      return ['home' => null, 'days_away' => 0];
    $home = $cities[0];
    $near = [];
    foreach($cities as $city) {
      if(Geo::distance((float)$home['lat'], (float)$home['lng'], (float)$city['lat'], (float)$city['lng']) <= self::HOME_RADIUS_METRES)
        $near[] = $city['id'];
    }
    // Days with any data, minus the days with time in or near the home city
    $withData = DB::table('stats_day_places')->where('database_id', $this->databaseId)
      ->whereBetween('local_date', [$from, $to])->distinct()->count('local_date');
    $atHome = DB::query()->fromSub($this->cityDays()->whereBetween('local_date', [$from, $to]), 'd')
      ->whereIn('city_id', $near)->distinct()->count('local_date');
    return [
      'home' => ['id' => $home['id'], 'name' => $home['name'], 'country' => $home['country']],
      'days_away' => $withData - $atHome,
    ];
  }

  // Current and longest run of consecutive active days, over all time
  public function streaks(): array {
    $days = $this->days('0000-01-01', '9999-12-31');
    $longest = ['days' => 0, 'from' => null, 'to' => null];
    $run = ['days' => 0, 'from' => null, 'to' => null];
    $previous = null;
    foreach($days as $date => $day) {
      if(!self::isActive($day))
        continue;
      $time = strtotime($date.' UTC');
      if($previous !== null && $time - $previous == 86400) {
        $run['days']++;
        $run['to'] = $date;
      } else {
        $run = ['days' => 1, 'from' => $date, 'to' => $date];
      }
      if($run['days'] > $longest['days'])
        $longest = $run;
      $previous = $time;
    }
    // The current streak is the last run, if it reaches today or yesterday
    // (today may simply not have happened yet where the server is)
    $current = ['days' => 0, 'from' => null, 'to' => null];
    if($run['to'] && strtotime($run['to'].' UTC') >= strtotime(gmdate('Y-m-d').' UTC') - 2 * 86400)
      $current = $run;
    return ['current' => $current, 'longest' => $longest];
  }

  // Per month: totals, places and distance by weekday (Monday first)
  private function months(int $year, array $days): array {
    $months = [];
    for($m = 1; $m <= 12; $m++) {
      $months[$m] = [
        'month' => $m, 'distance_m' => 0, 'active_days' => 0,
        'days_in_month' => (int)date('t', mktime(0, 0, 0, $m, 1, $year)),
        'countries' => 0, 'cities' => 0, 'weekdays' => array_fill(0, 7, 0), 'top_cities' => [],
      ];
    }
    foreach($days as $date => $day) {
      $m = (int)substr($date, 5, 2);
      $total = $day['distance_m'] + $day['flight_m'];
      $months[$m]['distance_m'] += $total;
      $months[$m]['active_days'] += self::isActive($day) ? 1 : 0;
      // Weekday pattern uses ground distance, or one long flight would hide everything else
      $months[$m]['weekdays'][(int)date('N', strtotime($date.' UTC')) - 1] += $day['distance_m'];
    }

    $countries = DB::table('stats_day_places')->where('database_id', $this->databaseId)
      ->whereBetween('local_date', ["$year-01-01", "$year-12-31"])->where('country', '<>', '')
      ->groupByRaw('MONTH(local_date)')->selectRaw('MONTH(local_date) AS m, COUNT(DISTINCT country) AS n')->pluck('n', 'm');
    foreach($countries as $m => $n)
      $months[$m]['countries'] = (int)$n;

    $cities = DB::query()->fromSub($this->cityDays()->whereBetween('local_date', ["$year-01-01", "$year-12-31"]), 'd')
      ->join('geo_cities AS c', 'c.id', '=', 'd.city_id')
      ->groupByRaw('MONTH(d.local_date), d.city_id, c.name, c.country')
      ->orderByRaw('m, days DESC, c.name')
      ->selectRaw('MONTH(d.local_date) AS m, c.name, c.country, COUNT(*) AS days')->get();
    foreach($cities as $row) {
      $months[$row->m]['cities']++;
      if(count($months[$row->m]['top_cities']) < 5)
        $months[$row->m]['top_cities'][] = ['name' => $row->name, 'country' => $row->country, 'days' => (int)$row->days];
    }
    return array_values($months);
  }

  // Distance, time and number of moves per mode of travel
  public function modes(string $from, string $to): array {
    return DB::table('stats_day_modes')->where('database_id', $this->databaseId)->whereBetween('local_date', [$from, $to])
      ->groupBy('mode')->orderByRaw('distance_m DESC')
      ->selectRaw('mode, SUM(distance_m) AS distance_m, SUM(duration_s) AS duration_s, SUM(moves) AS moves')
      ->get()->map(fn($row) => ['mode' => $row->mode, 'distance_m' => (int)$row->distance_m, 'duration_s' => (int)$row->duration_s, 'moves' => (int)$row->moves])->all();
  }

  // Cities and countries seen for the first time ever within the range
  public function firstVisits(string $from, string $to): array {
    $cities = DB::query()->fromSub($this->cityDays(), 'd')
      ->join('geo_cities AS c', 'c.id', '=', 'd.city_id')
      ->groupBy('d.city_id', 'c.name', 'c.country')
      ->havingRaw('first BETWEEN ? AND ?', [$from, $to])
      ->orderBy('first')
      ->selectRaw('c.name, c.country, MIN(d.local_date) AS first')
      ->get()->map(fn($row) => (array)$row)->all();
    $countries = DB::table('stats_day_places AS p')
      ->leftJoin('geo_countries AS c', 'c.code', '=', 'p.country')
      ->where('p.database_id', $this->databaseId)->where('p.country', '<>', '')
      ->groupBy('p.country', 'c.name')
      ->havingRaw('first BETWEEN ? AND ?', [$from, $to])
      ->orderBy('first')
      ->selectRaw('p.country AS code, c.name, MIN(p.local_date) AS first')
      ->get()->map(fn($row) => (array)$row)->all();
    return ['cities' => $cities, 'countries' => $countries];
  }

  // Years that have any stats, newest first
  public function years(): array {
    return DB::table('stats_days')->where('database_id', $this->databaseId)
      ->selectRaw('DISTINCT YEAR(local_date) AS y')->orderByDesc('y')->pluck('y')->map(fn($y) => (int)$y)->all();
  }

  // How many day files there are and how many have been processed
  public function progress(): array {
    $row = DB::table('stats_shards')->where('database_id', $this->databaseId)
      ->selectRaw('COUNT(*) AS files, COALESCE(SUM(version = ? AND dirty_at IS NULL), 0) AS done', [Version::PIPELINE])->first();
    return ['files' => (int)$row->files, 'processed' => (int)$row->done];
  }

}
