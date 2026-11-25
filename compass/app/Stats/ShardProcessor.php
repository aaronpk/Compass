<?php
namespace App\Stats;

use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use App\Stats\Geo\Airports;
use App\Stats\Geo\CountryIndex;
use App\Stats\Geo\DbCellStore;
use App\Stats\Geo\MysqlCityIndex;
use App\Stats\Geo\PlaceResolver;
use App\Stats\Geo\TimezoneResolver;

// Turns one UTC day file into rows for the stats tables.
//
// The day is segmented together with a few hours of the days on either side,
// so a stop or a drive that crosses UTC midnight is recognised for what it is.
// Segments are then cut at UTC midnight (the other half belongs to the
// neighbouring day file) and their time and distance are added up per local
// date: the date it was where the person was at that moment.
class ShardProcessor {

  // How much of the neighbouring days is looked at
  const CONTEXT_SECONDS = 3 * 3600;
  // Along a move, the country and timezone are checked this often
  const SAMPLE_METRES = 10000;

  private array $timezones = [];
  // The local day last looked up, per timezone: [start, end, date]
  private array $localDays = [];

  public function __construct(
    private PointReader $reader,
    private Segmenter $segmenter,
    private PlaceResolver $places,
    private TimezoneResolver $timezoneResolver,
    private string $defaultTimezone = 'UTC',
  ) {
    try {
      new DateTimeZone($this->defaultTimezone);
    } catch(\Exception $e) {
      $this->defaultTimezone = 'UTC';
    }
  }

  // A processor for one row of the databases table, using the lookup tables
  public static function forDatabase(object $database): static {
    static $shared;
    $shared ??= [
      new Segmenter(new Airports),
      new PlaceResolver(new CountryIndex, new MysqlCityIndex, new DbCellStore('geo_cells')),
      new TimezoneResolver(new DbCellStore('geo_tz_cells')),
    ];
    return new static(
      new PointReader(config('compass.storage_dir').$database->name),
      $shared[0], $shared[1], $shared[2],
      $database->timezone ?: 'UTC',
    );
  }

  // Processes one day file and replaces its rows. $date is Y-m-d (UTC).
  public function process(int $databaseId, string $date): array {
    $started = date('Y-m-d H:i:s');
    $result = $this->compute($date);

    DB::transaction(function() use($databaseId, $date, $result, $started) {
      DB::table('stats_segments')->where('database_id', $databaseId)->where('shard_date', $date)->delete();
      foreach(['stats_days', 'stats_day_modes', 'stats_day_places', 'stats_day_cells'] as $table)
        DB::table($table)->where('database_id', $databaseId)->where('utc_date', $date)->delete();

      $key = ['database_id' => $databaseId];
      foreach(array_chunk($result['segments'], 500) as $chunk)
        DB::table('stats_segments')->insert(array_map(fn($row) => $key + ['shard_date' => $date] + $row, $chunk));
      foreach(['stats_days' => 'days', 'stats_day_modes' => 'modes', 'stats_day_places' => 'places', 'stats_day_cells' => 'cells'] as $table => $name) {
        foreach(array_chunk($result[$name], 1000) as $chunk)
          DB::table($table)->insert(array_map(fn($row) => $key + ['utc_date' => $date] + $row, $chunk));
      }

      DB::table('stats_shards')->upsert([$key + ['shard_date' => $date] + $result['ledger'] + [
        'version' => Version::PIPELINE, 'processed_at' => date('Y-m-d H:i:s'), 'error' => null,
      ]], ['database_id', 'shard_date'], ['version', 'processed_at', 'error', 'file_size', 'file_mtime', 'points_total', 'points_used']);
      // Data that arrived while this was running leaves the day marked for another pass
      DB::table('stats_shards')->where('database_id', $databaseId)->where('shard_date', $date)
        ->where('dirty_at', '<=', $started)->update(['dirty_at' => null]);
    });

    return $result;
  }

  // Works out the rows for one day file without saving anything
  public function compute(string $date): array {
    $dayStart = (float)strtotime($date.' 00:00:00 UTC');
    $dayEnd = $dayStart + 86400;
    $day = $this->reader->day($date);
    $result = [
      'segments' => [], 'days' => [], 'modes' => [], 'places' => [], 'cells' => [],
      'ledger' => [
        'file_size' => $day['size'] ?? null, 'file_mtime' => $day['mtime'] ?? null,
        'points_total' => $day['lines'] ?? 0, 'points_used' => count($day['points'] ?? []),
      ],
    ];
    if(!$day || !$day['points'])
      return $result;

    [$points, $trips] = $this->window($date, $day, $dayStart, $dayEnd);
    $segments = $this->segmenter->segment($points, $trips);

    $days = $modes = $places = $cells = [];
    $timezone = $this->timezoneResolver->resolve($points[0][PointReader::LAT], $points[0][PointReader::LNG]) ?: $this->defaultTimezone;
    $seq = 0;

    foreach($segments as $segment) {
      if($segment['type'] == 'stop') {
        $timezone = $this->timezoneResolver->resolve($segment['lat'], $segment['lng']) ?: $timezone;
        $start = max($segment['start'], $dayStart);
        $end = min($segment['end'], $dayEnd);
        if($end <= $start)
          continue;
        $place = $this->places->resolve($segment['lat'], $segment['lng']);
        foreach($this->localSpans($start, $end, $timezone) as [$localDate, $seconds]) {
          self::add($days[$localDate]['stopped_s'], $seconds);
          self::add($places[$localDate][($place['country'] ?? '').'|'.($place['city_id'] ?? 0)]['stopped_s'], $seconds);
          self::add($cells[$localDate][self::cell($segment['lat'], $segment['lng'])], $seconds);
        }
        $result['segments'][] = $this->row($segment, $seq++, $start, $end, $timezone, 0, $place['country'], $place['city_id'], null);
        continue;
      }

      $isFlight = $segment['mode'] == 'plane';
      $mode = $segment['mode'] ?? '';
      $hopTotal = array_sum(array_column($segment['hops'], 2));
      $duration = $segment['end'] - $segment['start'];

      // A jump across a hole in the data, or a trip measured by the phone and
      // not traced point by point: spread it evenly over its duration
      if($segment['inferred'] || !$segment['hops'] || abs($segment['distance'] - $hopTotal) > 0.01 * max($hopTotal, 1)) {
        if($segment['inferred'] && $segment['mode_source'] == 'auto') {
          // Jumps aren't cut at UTC midnight. They belong to the day file they start in.
          if($segment['start'] < $dayStart || $segment['start'] >= $dayEnd)
            continue;
          $start = $segment['start'];
          $end = $segment['end'];
        } else {
          $start = max($segment['start'], $dayStart);
          $end = min($segment['end'], $dayEnd);
          if($end <= $start)
            continue;
        }
        $share = $duration > 0 ? ($end - $start) / $duration : 1;
        $distance = $segment['distance'] * $share;
        foreach($this->localSpans($start, $end, $timezone) as [$localDate, $seconds]) {
          $part = $end > $start ? $distance * $seconds / ($end - $start) : $distance;
          self::add($days[$localDate][$isFlight ? 'flight_distance_m' : 'distance_m'], $part);
          self::add($days[$localDate]['moving_s'], $seconds);
          self::add($modes[$localDate][$mode]['distance_m'], $part);
          self::add($modes[$localDate][$mode]['duration_s'], $seconds);
        }
        if($start == $segment['start'])
          self::add($modes[$this->localDate($start, $timezone)][$mode]['moves'], 1);
        $result['segments'][] = $this->row($segment, $seq++, $start, $end, $timezone, $distance, null, null, null);
        continue;
      }

      // A move traced point by point. Each hop counts toward the local date
      // it starts on, so the move is split exactly at local midnight.
      $start = max($segment['start'], $dayStart);
      $end = min($segment['end'], $dayEnd);
      $owned = $end > $start;
      $rowTimezone = $timezone;
      $distance = 0;
      $countries = [];
      $country = null;
      $sinceSample = INF;
      $lat = $segment['lat'];
      $lng = $segment['lng'];
      foreach($segment['hops'] as [$hopStart, $hopEnd, $metres, $hopLat, $hopLng]) {
        if($sinceSample >= self::SAMPLE_METRES) {
          $sinceSample = 0;
          $timezone = $this->timezoneResolver->resolve($lat, $lng) ?: $timezone;
          if(!$isFlight) {
            $country = $this->places->country($lat, $lng);
            if($country && $hopStart >= $dayStart && $hopStart < $dayEnd)
              $countries[$country] = true;
          }
          if($hopStart <= $start)
            $rowTimezone = $timezone;
        }
        $sinceSample += $metres;
        $lat = $hopLat;
        $lng = $hopLng;
        if($hopStart < $dayStart || $hopStart >= $dayEnd)
          continue;

        $localDate = $this->localDate($hopStart, $timezone);
        $seconds = $hopEnd - $hopStart;
        $distance += $metres;
        self::add($days[$localDate][$isFlight ? 'flight_distance_m' : 'distance_m'], $metres);
        self::add($days[$localDate]['moving_s'], $seconds);
        self::add($modes[$localDate][$mode]['distance_m'], $metres);
        self::add($modes[$localDate][$mode]['duration_s'], $seconds);
        if($country)
          self::add($places[$localDate][$country.'|0']['moving_s'], $seconds);
        // Where the track went on the ground, for the world map. Flying over a place isn't a visit.
        if(!$isFlight)
          $cells[$localDate][self::cell($hopLat, $hopLng)] ??= 0;
      }
      if(!$owned)
        continue;
      if($start == $segment['start'])
        self::add($modes[$this->localDate($start, $rowTimezone)][$mode]['moves'], 1);
      $result['segments'][] = $this->row($segment, $seq++, $start, $end, $rowTimezone, $distance, null, null, array_keys($countries));
    }

    foreach($days as $localDate => $values) {
      $result['days'][] = ['local_date' => $localDate] + self::rounded($values, ['distance_m', 'flight_distance_m', 'moving_s', 'stopped_s']);
    }
    foreach($modes as $localDate => $byMode) {
      foreach($byMode as $mode => $values)
        $result['modes'][] = ['local_date' => $localDate, 'mode' => (string)$mode] + self::rounded($values, ['distance_m', 'duration_s', 'moves']);
    }
    foreach($places as $localDate => $byPlace) {
      foreach($byPlace as $place => $values) {
        [$country, $city] = explode('|', $place);
        $result['places'][] = ['local_date' => $localDate, 'country' => $country, 'city_id' => (int)$city] + self::rounded($values, ['stopped_s', 'moving_s']);
      }
    }
    foreach($cells as $localDate => $byCell) {
      foreach($byCell as $cell => $seconds) {
        [$latI, $lngI] = explode('|', $cell);
        $result['cells'][] = ['local_date' => $localDate, 'lat_i' => (int)$latI, 'lng_i' => (int)$lngI, 'stopped_s' => (int)round($seconds)];
      }
    }
    return $result;
  }

  // The tenth-of-a-degree cell a point is in, as "lat|lng" in tenths
  private static function cell(float $lat, float $lng): string {
    return (int)floor($lat * 10).'|'.(int)floor($lng * 10);
  }

  // The day's points with a few hours of its neighbours on either side, plus
  // the nearest point beyond that, so a hole in the data that runs past the
  // context still has both of its ends
  private function window(string $date, array $day, float $dayStart, float $dayEnd): array {
    $points = [];
    $trips = [];

    if($previous = $this->reader->day(gmdate('Y-m-d', (int)$dayStart - 86400))) {
      $from = $dayStart - self::CONTEXT_SECONDS;
      $count = count($previous['points']);
      $first = $count;
      while($first > 0 && $previous['points'][$first - 1][PointReader::T] >= $from)
        $first--;
      $points = array_slice($previous['points'], max(0, $first - 1));
      $trips = $previous['trips'];
    }

    $points = array_merge($points, $day['points']);
    $trips = array_merge($trips, $day['trips']);

    if($next = $this->reader->day(gmdate('Y-m-d', (int)$dayEnd))) {
      $to = $dayEnd + self::CONTEXT_SECONDS;
      $last = 0;
      $count = count($next['points']);
      while($last < $count && $next['points'][$last][PointReader::T] < $to)
        $last++;
      $points = array_merge($points, array_slice($next['points'], 0, min($count, $last + 1)));
      $trips = array_merge($trips, $next['trips']);
    }

    return [$points, $trips];
  }

  private function row(array $segment, int $seq, float $start, float $end, string $timezone, float $distance, ?string $country, ?int $cityId, ?array $countries): array {
    return [
      'seq' => $seq,
      'type' => $segment['type'],
      'inferred' => $segment['inferred'] ? 1 : 0,
      'mode' => $segment['mode'],
      'mode_source' => $segment['mode_source'],
      'motion' => $segment['motion'],
      'start_at' => gmdate('Y-m-d H:i:s', (int)$start),
      'end_at' => gmdate('Y-m-d H:i:s', (int)$end),
      'tz' => $timezone,
      'local_date' => $this->localDate($start, $timezone),
      'duration_s' => (int)round($end - $start),
      'distance_m' => (int)round($distance),
      'reported_distance_m' => $segment['reported_distance'] === null ? null : (int)round($segment['reported_distance']),
      'start_lat' => round($segment['lat'], 5),
      'start_lng' => round($segment['lng'], 5),
      'end_lat' => round($segment['end_lat'], 5),
      'end_lng' => round($segment['end_lng'], 5),
      'points' => $segment['points'],
      'speed_p50' => $segment['speed_p50'] === null ? null : round(min($segment['speed_p50'], 9999), 2),
      'speed_p95' => $segment['speed_p95'] === null ? null : round(min($segment['speed_p95'], 9999), 2),
      'alt_max' => $segment['alt_max'] === null ? null : (int)round($segment['alt_max']),
      'country' => $country,
      'city_id' => $cityId,
      'countries' => $countries ? substr(implode(',', $countries), 0, 60) : null,
      'clipped_start' => $start > $segment['start'] ? 1 : 0,
      'clipped_end' => $end < $segment['end'] ? 1 : 0,
    ];
  }

  // The local date at a moment in a timezone
  private function localDate(float $time, string $timezone): string {
    $cached = $this->localDays[$timezone] ?? null;
    if($cached && $time >= $cached[0] && $time < $cached[1])
      return $cached[2];

    $zone = $this->timezones[$timezone] ??= new DateTimeZone($timezone);
    $local = (new DateTime('@'.(int)floor($time)))->setTimezone($zone);
    $date = $local->format('Y-m-d');
    $start = (clone $local)->setTime(0, 0)->getTimestamp();
    $end = (clone $local)->modify('tomorrow')->getTimestamp();
    $this->localDays[$timezone] = [$start, $end, $date];
    return $date;
  }

  // Cuts a time span at local midnights: [[local date, seconds], ...]
  private function localSpans(float $start, float $end, string $timezone): array {
    $spans = [];
    $from = $start;
    while($from < $end) {
      $date = $this->localDate($from, $timezone);
      $to = min($end, $this->localDays[$timezone][1]);
      $spans[] = [$date, $to - $from];
      $from = $to;
    }
    return $spans;
  }

  private static function add(&$value, float $amount): void {
    $value = ($value ?? 0) + $amount;
  }

  private static function rounded(array $values, array $columns): array {
    $row = [];
    foreach($columns as $column)
      $row[$column] = (int)round($values[$column] ?? 0);
    return $row;
  }

}
