<?php
namespace Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use App\Stats\Segmenter;
use App\Stats\Geo\Airports;

class SegmenterTest extends TestCase {

  const PDX = [45.5898, -122.5951];
  const SEA = [47.4502, -122.3088];
  const NRT = [35.7720, 140.3929];

  private function segmenter(): Segmenter {
    return new Segmenter(new Airports(dirname(__DIR__, 3).'/resources/geo/airports.csv'));
  }

  private function shape(array $segments): array {
    return array_map(fn($s) => $s['type'].($s['inferred'] ? '?' : '').($s['mode'] ? ':'.$s['mode'] : ''), $segments);
  }

  private function distance(array $segments): float {
    return array_sum(array_column($segments, 'distance'));
  }

  public function testNoPointsNoSegments() {
    $this->assertSame([], $this->segmenter()->segment([]));
  }

  public function testEightHoursStandingStillIsOneStopAndNoDistance() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))->stay(8 * 3600, 15);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop'], $this->shape($segments));
    $this->assertSame(0.0, $this->distance($segments));
    $this->assertEqualsWithDelta(8 * 3600, $segments[0]['end'] - $segments[0]['start'], 30);
    $this->assertEqualsWithDelta(45.52, $segments[0]['lat'], 0.0002);
  }

  public function testAStraightTenKilometreDriveIsMeasuredWithinTwoPercent() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))
      ->stay(900)->go(10000, 90, 900)->stay(900);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop', 'move', 'stop'], $this->shape($segments));
    $this->assertEqualsWithDelta(10000, $segments[1]['distance'], 200);
    $this->assertNull($segments[1]['mode'], 'Ground transport is not guessed without a trip record');
    $this->assertSame('driving', $segments[1]['motion']);
    $this->assertEqualsWithDelta(11.1, $segments[1]['speed_p50'], 0.5);
  }

  public function testASingleStrayPointAddsNoDistance() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))
      ->stay(900, 5)->glitch(40000)->stay(900, 5);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop'], $this->shape($segments));
    $this->assertSame(0.0, $this->distance($segments));
  }

  public function testAFourMinutePauseDoesNotSplitAMove() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))
      ->stay(900)->go(5000, 90, 600)->stay(240, 5)->go(5000, 90, 600)->stay(900);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop', 'move', 'stop'], $this->shape($segments));
    $this->assertEqualsWithDelta(10000, $segments[1]['distance'], 200);
  }

  public function testASixMinutePauseIsAStop() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))
      ->stay(900)->go(5000, 90, 600)->stay(360, 5)->go(5000, 90, 600)->stay(900);
    $this->assertSame(['stop', 'move', 'stop', 'move', 'stop'], $this->shape($this->segmenter()->segment($track->points)));
  }

  public function testInaccuratePointsDoNotMoveTheTrack() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))->stay(1800, 5);
    // Cell-tower quality fixes scattered over a kilometre
    $noisy = (new TrackBuilder('2026-01-10 08:00:02', 45.52, -122.68, 7))->stay(1800, 1000, 60, 1500);
    $points = array_merge($track->points, $noisy->points);
    usort($points, fn($a, $b) => $a[0] <=> $b[0]);
    $segments = $this->segmenter()->segment($points);
    $this->assertSame(['stop'], $this->shape($segments));
    $this->assertSame(0.0, $this->distance($segments));
  }

  public function testPhoneOffOvernightInTheSamePlaceStaysOneStop() {
    $track = (new TrackBuilder('2026-01-10 20:00:00', 45.52, -122.68))
      ->stay(3600)->gap(8 * 3600)->stay(3600);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop'], $this->shape($segments));
    $this->assertEqualsWithDelta(10 * 3600, $segments[0]['end'] - $segments[0]['start'], 60);
  }

  public function testAFlightWithThePhoneOffIsAnInferredPlaneMove() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', ...self::PDX))
      ->stay(3600)->gap(10 * 3600, ...self::NRT)->stay(3600);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop', 'move?:plane', 'stop'], $this->shape($segments));
    $this->assertEqualsWithDelta(7_750_000, $segments[1]['distance'], 100_000);
    $this->assertEqualsWithDelta(10 * 3600, $segments[1]['end'] - $segments[1]['start'], 60);
  }

  public function testASlowJumpIsAFlightOnlyBetweenAirports() {
    // Portland to San Francisco airport, 885 km, with the phone off for six
    // hours: too slow door to door for the speed rule, so the airports decide
    $airports = (new TrackBuilder('2026-01-10 08:00:00', ...self::PDX))
      ->stay(1800)->gap(6 * 3600, 37.6213, -122.3790)->stay(1800);
    $this->assertSame(['stop', 'move?:plane', 'stop'], $this->shape($this->segmenter()->segment($airports->points)));

    // The same jump between two places with no airport nearby
    $elsewhere = (new TrackBuilder('2026-01-10 08:00:00', 44.30, -120.80))
      ->stay(1800)->gap(6 * 3600, 36.40, -120.10)->stay(1800);
    $this->assertSame(['stop', 'move?', 'stop'], $this->shape($this->segmenter()->segment($elsewhere->points)));

    // Portland to Seattle airport is 208 km: under the 300 km the airport rule needs
    $short = (new TrackBuilder('2026-01-10 08:00:00', ...self::PDX))
      ->stay(1800)->gap(2 * 3600, ...self::SEA)->stay(1800);
    $this->assertSame(['stop', 'move?', 'stop'], $this->shape($this->segmenter()->segment($short->points)));
  }

  public function testAJumpWithNoAirportsNearbyIsNotAFlightUnlessLongAndFast() {
    // 60 km in an hour with the phone off: a drive, not a flight
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))
      ->stay(1800)->gap(3600, 45.52, -121.90)->stay(1800);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop', 'move?', 'stop'], $this->shape($segments));
    $this->assertEqualsWithDelta(60800, $segments[1]['distance'], 1000);
  }

  public function testAFlightRecordedByGpsIsAPlaneMove() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', ...self::PDX))
      ->stay(1800)
      ->travel(47.0, -122.4, 900, 10, null, 10, 9000)   // about 157 km in 15 minutes
      ->travel(...[...self::SEA, 300, 10, null, 10, 2000])
      ->stay(1800);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop', 'move:plane', 'stop'], $this->shape($segments));
    $this->assertFalse($segments[1]['inferred']);
    $this->assertGreaterThan(9000 - 1, $segments[1]['alt_max']);
  }

  public function testATripRecordSetsTheModeAndSwallowsShortStops() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))->stay(1800);
    $tripStart = $track->time;
    $track->go(3000, 90, 600, 5, 'cycling')->stay(420, 5)->go(3000, 90, 600, 5, 'cycling');
    $trip = $track->trip('bicycle', $tripStart, 6100);
    $track->stay(1800);

    $segments = $this->segmenter()->segment($track->points, [$trip]);
    $this->assertSame(['stop', 'move:bicycle', 'stop'], $this->shape($segments));
    $this->assertSame('trip', $segments[1]['mode_source']);
    $this->assertEqualsWithDelta(6000, $segments[1]['distance'], 150);
    $this->assertSame(6100.0, $segments[1]['reported_distance']);
    $this->assertEqualsWithDelta($tripStart, $segments[1]['start'], 1);
    // The stops on either side end and begin at the trip's edges
    $this->assertEqualsWithDelta($tripStart, $segments[0]['end'], 1);
    $this->assertEqualsWithDelta($trip['end'], $segments[2]['start'], 1);
  }

  public function testATripWithAlmostNoPointsUsesTheDistanceThePhoneReported() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))->stay(1800);
    $tripStart = $track->time;
    $track->gap(3600, 45.60, -122.20);
    $trip = $track->trip('train', $tripStart, 52000);
    $track->stay(1800);
    $segments = $this->segmenter()->segment($track->points, [$trip]);
    $this->assertSame(['stop', 'move:train', 'stop'], $this->shape($segments));
    $this->assertSame(52000.0, $segments[1]['distance']);
  }

  public function testOldDataWithNoAccuracyOrMotionStillSegments() {
    $track = (new TrackBuilder('2010-06-01 08:00:00', 45.52, -122.68))
      ->stay(1800, 20, 30, null)->go(8000, 0, 1200, 30, null, null)->stay(1800, 20, 30, null);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop', 'move', 'stop'], $this->shape($segments));
    $this->assertEqualsWithDelta(8000, $segments[1]['distance'], 250);
    $this->assertNull($segments[1]['motion']);
  }

  public function testSegmentsAreContinuousAndInOrder() {
    $track = (new TrackBuilder('2026-01-10 08:00:00', 45.52, -122.68))
      ->stay(900)->go(4000, 90, 600)->stay(900)->go(2000, 180, 1200, 5, 'walking')->stay(900);
    $segments = $this->segmenter()->segment($track->points);
    $this->assertSame(['stop', 'move', 'stop', 'move', 'stop'], $this->shape($segments));
    for($i = 1; $i < count($segments); $i++)
      $this->assertEqualsWithDelta($segments[$i - 1]['end'], $segments[$i]['start'], 0.001);
    $this->assertSame('walking', $segments[3]['motion']);
  }

}
