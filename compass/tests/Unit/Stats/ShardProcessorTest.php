<?php
namespace Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use App\Stats\PointReader;
use App\Stats\Segmenter;
use App\Stats\ShardProcessor;
use App\Stats\Geo\ArrayCellStore;
use App\Stats\Geo\ArrayCityIndex;
use App\Stats\Geo\CountryIndex;
use App\Stats\Geo\PlaceResolver;
use App\Stats\Geo\TimezoneResolver;

// Runs whole day files through the pipeline, without a database: the files
// are written to a temporary directory in the same format quartz-db uses.
class ShardProcessorTest extends TestCase {

  const PORTLAND = [45.5152, -122.6784];
  const TOKYO = [35.6812, 139.7671];
  const AUCKLAND = [-36.8485, 174.7633];
  const HONOLULU = [21.3069, -157.8583];

  private string $dir;

  protected function setUp(): void {
    $this->dir = sys_get_temp_dir().'/compass-stats-test-'.getmypid().'-'.uniqid();
    mkdir($this->dir);
  }

  protected function tearDown(): void {
    exec('rm -rf '.escapeshellarg($this->dir));
  }

  // Writes points (and trip records) into day files, deliberately out of order
  private function write(array $points, array $trips = []): void {
    $lines = [];
    foreach($points as [$time, $lat, $lng, $accuracy, $speed, $altitude, $motion]) {
      $properties = ['timestamp' => gmdate('c', (int)$time)];
      if($accuracy !== null) $properties['horizontal_accuracy'] = $accuracy;
      if($speed !== null) $properties['speed'] = $speed;
      if($altitude !== null) $properties['altitude'] = $altitude;
      if($motion !== null) $properties['motion'] = [$motion];
      $lines[] = [$time, ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]], 'properties' => $properties]];
    }
    foreach($trips as $trip) {
      $lines[] = [$trip['end'], ['type' => 'Feature', 'geometry' => ['type' => 'Point', 'coordinates' => [$trip['to'][1], $trip['to'][0]]], 'properties' => [
        'type' => 'trip', 'mode' => $trip['mode'], 'distance' => $trip['distance'],
        'start' => gmdate('c', (int)$trip['start']), 'end' => gmdate('c', (int)$trip['end']), 'timestamp' => gmdate('c', (int)$trip['end']),
      ]]];
    }
    $files = [];
    foreach($lines as [$time, $record]) {
      $path = $this->dir.'/'.gmdate('Y/m/d', (int)$time).'.txt';
      $micro = sprintf('%06d', round(($time - floor($time)) * 1e6));
      $files[$path][] = gmdate('Y-m-d H:i:s', (int)$time).'.'.$micro.' '.json_encode($record);
    }
    foreach($files as $path => $fileLines) {
      // Late data is appended, so files aren't in time order
      $fileLines = array_merge(array_slice($fileLines, 10), array_slice($fileLines, 0, 10));
      @mkdir(dirname($path), 0777, true);
      file_put_contents($path, implode("\n", $fileLines)."\n\n", FILE_APPEND);
    }
  }

  private function processor(string $defaultTimezone = 'UTC'): ShardProcessor {
    $cities = [
      ['id' => 1, 'name' => 'Portland', 'country' => 'US', 'admin1' => 'OR', 'lat' => 45.52345, 'lng' => -122.67621, 'population' => 650000, 'tz' => 'America/Los_Angeles'],
      ['id' => 2, 'name' => 'Tokyo', 'country' => 'JP', 'admin1' => null, 'lat' => 35.6895, 'lng' => 139.69171, 'population' => 9000000, 'tz' => 'Asia/Tokyo'],
      ['id' => 3, 'name' => 'Auckland', 'country' => 'NZ', 'admin1' => null, 'lat' => -36.84853, 'lng' => 174.76349, 'population' => 400000, 'tz' => 'Pacific/Auckland'],
      ['id' => 4, 'name' => 'Honolulu', 'country' => 'US', 'admin1' => 'HI', 'lat' => 21.30694, 'lng' => -157.85833, 'population' => 350000, 'tz' => 'Pacific/Honolulu'],
    ];
    return new ShardProcessor(
      new PointReader($this->dir),
      new Segmenter,
      new PlaceResolver(new CountryIndex(dirname(__DIR__, 3).'/resources/geo'), new ArrayCityIndex($cities), new ArrayCellStore),
      new TimezoneResolver(new ArrayCellStore),
      $defaultTimezone,
    );
  }

  // Adds up a column over several day files, grouped by local date
  private function totals(array $dates, string $table, string $column, ?callable $filter = null): array {
    $processor = $this->processor();
    $totals = [];
    foreach($dates as $date) {
      foreach($processor->compute($date)[$table] as $row) {
        if($filter && !$filter($row))
          continue;
        $totals[$row['local_date']] = ($totals[$row['local_date']] ?? 0) + $row[$column];
      }
    }
    ksort($totals);
    return $totals;
  }

  public function testADayWithNoFileGivesNoRows() {
    $result = $this->processor()->compute('2026-01-10');
    $this->assertSame([], $result['segments']);
    $this->assertSame(0, $result['ledger']['points_total']);
  }

  public function testOneDayInPortland() {
    // 18:00 UTC is 10:00 in Portland
    $track = (new TrackBuilder('2026-01-10 18:00:00', ...self::PORTLAND))
      ->stay(3600)->go(10000, 90, 900)->stay(3600);
    $this->write($track->points);
    $result = $this->processor()->compute('2026-01-10');

    $this->assertSame(['stop', 'move', 'stop'], array_column($result['segments'], 'type'));
    $this->assertSame('America/Los_Angeles', $result['segments'][0]['tz']);
    $this->assertSame('2026-01-10', $result['segments'][0]['local_date']);
    $this->assertSame('US', $result['segments'][0]['country']);
    $this->assertSame(1, $result['segments'][0]['city_id']);
    $this->assertSame('US', $result['segments'][1]['countries']);

    $this->assertCount(1, $result['days']);
    $this->assertEqualsWithDelta(10000, $result['days'][0]['distance_m'], 200);
    $this->assertEqualsWithDelta(7200, $result['days'][0]['stopped_s'], 60);
    $this->assertEqualsWithDelta(900, $result['days'][0]['moving_s'], 30);
    $this->assertSame(1, $result['modes'][0]['moves']);
    // Two points in the same second count once
    $this->assertSame(count(array_unique(array_map('intval', array_column($track->points, 0)))), $result['ledger']['points_used']);
  }

  public function testAnEveningAcrossUtcMidnightIsOneLocalDayFromTwoFiles() {
    // 22:00 UTC to 02:00 UTC is 14:00 to 18:00 in Portland
    $track = (new TrackBuilder('2026-01-10 22:00:00', ...self::PORTLAND))
      ->stay(3600)->go(20000, 90, 7200, 5)->stay(3600);
    $this->write($track->points);

    $first = $this->processor()->compute('2026-01-10');
    $second = $this->processor()->compute('2026-01-11');
    // The drive is cut at UTC midnight and each file holds its half
    $this->assertSame(1, $first['segments'][1]['clipped_end']);
    $this->assertSame(1, $second['segments'][0]['clipped_start']);
    // The stop lasts until the car has left its radius, a few seconds after 23:00
    $this->assertEqualsWithDelta(strtotime('2026-01-10 23:00:00 UTC'), strtotime($first['segments'][1]['start_at'].' UTC'), 60);
    $this->assertSame('2026-01-11 00:00:00', $first['segments'][1]['end_at']);
    $this->assertSame('2026-01-11 00:00:00', $second['segments'][0]['start_at']);

    // Summed by local date, nothing is lost or counted twice
    $distance = $this->totals(['2026-01-10', '2026-01-11'], 'days', 'distance_m');
    $this->assertSame(['2026-01-10'], array_keys($distance));
    $this->assertEqualsWithDelta(20000, $distance['2026-01-10'], 300);
    $moves = $this->totals(['2026-01-10', '2026-01-11'], 'modes', 'moves');
    $this->assertSame(1, $moves['2026-01-10'], 'A move cut in two is still one move');
    $stopped = $this->totals(['2026-01-10', '2026-01-11'], 'days', 'stopped_s');
    $this->assertEqualsWithDelta(7200, $stopped['2026-01-10'], 60);
  }

  public function testAStopOverLocalMidnightIsSplitBetweenTheTwoLocalDates() {
    // 06:00 to 10:00 UTC is 22:00 to 02:00 in Portland
    $track = (new TrackBuilder('2026-01-10 06:00:00', ...self::PORTLAND))->stay(4 * 3600);
    $this->write($track->points);
    $stopped = $this->totals(['2026-01-10'], 'days', 'stopped_s');
    $this->assertEqualsWithDelta(7200, $stopped['2026-01-09'], 30);
    $this->assertEqualsWithDelta(7200, $stopped['2026-01-10'], 30);
  }

  public function testAWestboundFlightMakesTheOriginDayLongAndSkipsNothing() {
    // Leaves Portland at 15:00 local on the 10th (23:00 UTC), phone off for
    // 11 hours, lands in Tokyo at 19:00 local on the 11th (10:00 UTC)
    $track = (new TrackBuilder('2026-01-10 21:00:00', ...self::PORTLAND))
      ->stay(2 * 3600)->gap(11 * 3600, ...self::TOKYO)->stay(4 * 3600);
    $this->write($track->points);
    $dates = ['2026-01-10', '2026-01-11'];

    $flight = $this->totals($dates, 'days', 'flight_distance_m');
    // The flight is counted in Portland time until landing: 9 hours on the
    // 10th and 2 on the 11th
    $this->assertEqualsWithDelta(7_800_000, array_sum($flight), 100_000);
    $this->assertEqualsWithDelta(9 / 11, $flight['2026-01-10'] / array_sum($flight), 0.01);
    $this->assertSame(0, array_sum($this->totals($dates, 'days', 'distance_m')));

    $result = $this->processor()->compute('2026-01-10');
    $jump = $result['segments'][1];
    $this->assertSame(['move', 1, 'plane'], [$jump['type'], $jump['inferred'], $jump['mode']]);
    $this->assertSame('2026-01-11 10:00:00', $jump['end_at'], 'A jump is not cut at UTC midnight');
    $this->assertSame(0, $jump['clipped_end']);
    // It belongs to the file it starts in, and only that one
    $this->assertSame(['stop'], array_column($this->processor()->compute('2026-01-11')['segments'], 'type'));

    // After landing, time is counted in Tokyo: 19:00 to 23:00 on the 11th
    $tokyo = $this->totals($dates, 'places', 'stopped_s', fn($row) => $row['country'] == 'JP');
    $this->assertSame(['2026-01-11'], array_keys($tokyo));
    // Countries flown over are not recorded
    $countries = [];
    foreach($dates as $date)
      $countries = array_merge($countries, array_column($this->processor()->compute($date)['places'], 'country'));
    $this->assertEqualsCanonicalizing(['US', 'JP'], array_values(array_unique($countries)));
  }

  public function testCrossingTheDateLineEastboundRepeatsALocalDate() {
    // Leaves Auckland at 10:00 on the 11th (21:00 UTC on the 10th), lands in
    // Honolulu nine hours later, at 20:00 local on the 10th
    $track = (new TrackBuilder('2026-01-10 19:00:00', ...self::AUCKLAND))
      ->stay(2 * 3600)->gap(9 * 3600, ...self::HONOLULU)->stay(3 * 3600);
    $this->write($track->points);
    $stopped = $this->totals(['2026-01-10', '2026-01-11'], 'days', 'stopped_s');
    // 08:00-10:00 on the 11th in Auckland, then 20:00-23:00 on the 10th in Honolulu
    $this->assertEqualsWithDelta(2 * 3600, $stopped['2026-01-11'], 60);
    $this->assertEqualsWithDelta(3 * 3600, $stopped['2026-01-10'], 60);
  }

  public function testATripRecordGivesTheMoveItsModeInTheDayTotals() {
    $track = (new TrackBuilder('2026-01-10 18:00:00', ...self::PORTLAND))->stay(1800);
    $start = $track->time;
    $track->go(6000, 90, 1200, 5, 'cycling');
    $trip = $track->trip('bicycle', $start, 6100);
    $track->stay(1800);
    $this->write($track->points, [$trip]);

    $result = $this->processor()->compute('2026-01-10');
    $this->assertSame(['bicycle'], array_column($result['modes'], 'mode'));
    $this->assertEqualsWithDelta(6000, $result['modes'][0]['distance_m'], 150);
    $this->assertSame('trip', $result['segments'][1]['mode_source']);
    $this->assertSame(6100, $result['segments'][1]['reported_distance_m']);
    // The trip record itself isn't a point, and two points share a second
    $this->assertSame(count($track->points) + 1, $result['ledger']['points_total']);
    $this->assertSame(count(array_unique(array_map('intval', array_column($track->points, 0)))), $result['ledger']['points_used']);
  }

  public function testOpenOceanFallsBackToTheLastTimezoneThenTheDatabaseSetting() {
    // Mid-Pacific, with nothing before it: the database's timezone applies
    $track = (new TrackBuilder('2026-01-10 05:00:00', 0.0, -140.0))->stay(2 * 3600);
    $this->write($track->points);
    $result = $this->processor('Pacific/Honolulu')->compute('2026-01-10');
    $this->assertSame('Pacific/Honolulu', $result['segments'][0]['tz']);
    $this->assertSame('2026-01-09', $result['segments'][0]['local_date']);
    $this->assertNull($result['segments'][0]['country']);
  }

  // Cells (tenths of a degree) per local date: [date => [cell => stopped seconds]]
  private function cells(array $dates): array {
    $processor = $this->processor();
    $cells = [];
    foreach($dates as $date) {
      foreach($processor->compute($date)['cells'] as $row) {
        $key = $row['lat_i'].'|'.$row['lng_i'];
        $cells[$row['local_date']][$key] = ($cells[$row['local_date']][$key] ?? 0) + $row['stopped_s'];
      }
    }
    return $cells;
  }

  public function testAStopGivesOneCellWithItsTime() {
    $track = (new TrackBuilder('2026-01-10 18:00:00', ...self::PORTLAND))->stay(3600);
    $this->write($track->points);
    $cells = $this->cells(['2026-01-10']);
    $this->assertSame(['2026-01-10'], array_keys($cells));
    // 45.5152, -122.6784 is in the cell from 45.5 and -122.7
    $this->assertSame(['455|-1227'], array_keys($cells['2026-01-10']));
    $this->assertEqualsWithDelta(3600, $cells['2026-01-10']['455|-1227'], 30);
  }

  public function testADriveLeavesALineOfCellsThatWereOnlyPassedThrough() {
    // 60 km due east at 45.5 N is about 0.77 degrees of longitude: 8 or 9 cells
    $track = (new TrackBuilder('2026-01-10 18:00:00', ...self::PORTLAND))
      ->stay(3600)->go(60000, 90, 3600)->stay(3600);
    $this->write($track->points);
    $cells = $this->cells(['2026-01-10'])['2026-01-10'];
    $this->assertGreaterThanOrEqual(8, count($cells));
    $this->assertLessThanOrEqual(10, count($cells));
    // Stopped at both ends and nowhere in between
    $this->assertCount(2, array_filter($cells));
    $this->assertGreaterThan(0, $cells['455|-1227']);
    $longitudes = array_map(fn($cell) => (int)explode('|', $cell)[1], array_keys($cells));
    sort($longitudes);
    $this->assertSame(range($longitudes[0], end($longitudes)), $longitudes, 'No cell along the road is skipped');
  }

  public function testAFlightWithTheGpsOnLeavesNoCellsUnderIt() {
    // Portland to Honolulu in five and a half hours, recording all the way
    $track = (new TrackBuilder('2026-01-10 18:00:00', ...self::PORTLAND))
      ->stay(3600)
      ->travel(self::HONOLULU[0], self::HONOLULU[1], (int)(5.5 * 3600), 30, null, 10, 10000)
      ->stay(3600);
    $this->write($track->points);
    $dates = ['2026-01-10', '2026-01-11'];
    $modes = [];
    foreach($dates as $date)
      $modes = array_merge($modes, array_column($this->processor()->compute($date)['segments'], 'mode'));
    $this->assertContains('plane', $modes);

    $cells = [];
    foreach($this->cells($dates) as $byCell)
      $cells += $byCell;
    // Only the two airports, give or take a neighbouring cell
    $this->assertLessThanOrEqual(4, count($cells));
    $this->assertArrayHasKey('455|-1227', $cells);
    $this->assertArrayHasKey('213|-1579', $cells);
  }

}
