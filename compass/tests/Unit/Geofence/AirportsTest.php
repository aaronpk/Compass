<?php
namespace Tests\Unit\Geofence;

use App\Geofence\Airports;
use App\Geofence\PlaceWatcher;
use App\Stats\Geo\Geo;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Stats\TrackBuilder;

class AirportsTest extends TestCase {

  const START = '2026-01-10 08:00:00';
  const FILE = __DIR__.'/../../../resources/geo/airport-places.csv';

  // One airport, 1500 m around the test spot, known about from 500 m outside its edge
  private function watcher(): PlaceWatcher {
    return new PlaceWatcher(function(float $lat, float $lng) {
      $outside = Geo::distance($lat, $lng, ...ShapesTest::at(0, 0)) - 1500;
      return ['country' => null, 'region' => null, 'city' => null, 'airport' => $outside <= 500 ? ['TST', 'Test Field', $outside] : null];
    });
  }

  private static function track(float $east): TrackBuilder {
    return new TrackBuilder(self::START, ...ShapesTest::at(0, $east));
  }

  const NOWHERE = ['a' => ['value' => '', 'label' => null]];
  const AT_TST = ['a' => ['value' => 'TST', 'label' => 'Test Field']];

  public function testBeingAtNoAirportIsKnownWithoutAnEvent() {
    [$states, $events] = $this->watcher()->run(['a' => 'airport'], [], self::track(-8000)->stay(600)->points, null);
    $this->assertSame([], $events);
    $this->assertSame('', $states['a']['value']);
    $this->assertNotNull($states['a']['since']);
  }

  public function testStartingOutAtAnAirportIsKnownWithoutAnEvent() {
    [$states, $events] = $this->watcher()->run(['a' => 'airport'], [], self::track(0)->stay(600)->points, null);
    $this->assertSame([], $events);
    $this->assertSame('TST', $states['a']['value']);
  }

  public function testArrivingIsAnEnterNamingTheAirport() {
    $track = self::track(-8000)->stay(120)->go(8000, 90, 600)->stay(600);
    [$states, $events] = $this->watcher()->run(['a' => 'airport'], self::NOWHERE, $track->points, null);
    $this->assertSame(['enter'], array_column($events, 'type'));
    $this->assertSame(['TST', 'Test Field'], $events[0]['to']);
    $this->assertArrayNotHasKey('from', $events[0]);
    $this->assertSame('TST', $states['a']['value']);
    // Timed at the first point inside, about 1500 m from the centre
    $this->assertEqualsWithDelta(1500, Geo::distance($events[0]['lat'], $events[0]['lng'], ...ShapesTest::at(0, 0)), 200);
  }

  public function testLeavingIsAnExitNamingTheAirport() {
    $track = self::track(0)->stay(120)->go(8000, 90, 600)->stay(600);
    [$states, $events] = $this->watcher()->run(['a' => 'airport'], self::AT_TST, $track->points, null);
    $this->assertSame(['exit'], array_column($events, 'type'));
    $this->assertSame(['TST', 'Test Field'], $events[0]['from']);
    $this->assertSame('', $states['a']['value']);
    $this->assertNull($states['a']['label']);
  }

  public function testDrivingPastDoesNothing() {
    // Straight through the middle at 30 m/s: inside for 100 seconds
    $track = self::track(-8000)->stay(120)->go(16000, 90, 533)->stay(600);
    [$states, $events] = $this->watcher()->run(['a' => 'airport'], self::NOWHERE, $track->points, null);
    $this->assertSame([], $events);
    $this->assertSame('', $states['a']['value']);
  }

  public function testJustPastTheEdgeIsStillThere() {
    // 200 m outside the edge: not far enough to have left, but not close enough to arrive
    $at = self::track(1700)->stay(900)->points;
    [$states, $events] = $this->watcher()->run(['a' => 'airport'], self::AT_TST, $at, null);
    $this->assertSame([], $events);
    $this->assertSame('TST', $states['a']['value']);
    [$states, $events] = $this->watcher()->run(['a' => 'airport'], self::NOWHERE, $at, null);
    $this->assertSame([], $events);
    $this->assertSame('', $states['a']['value']);
  }

  public function testTheListFindsAnAirportBySizeAndNothingElsewhere() {
    $airports = new Airports(self::FILE);
    // Portland International is a large airport: 2 km from its centre is inside
    $pdx = $airports->at(45.5887 + 2000 / 111195, -122.5980);
    $this->assertSame('PDX', $pdx[0]);
    $this->assertSame('Portland International Airport', $pdx[1]);
    $this->assertEqualsWithDelta(-500, $pdx[2], 20);
    // Just outside it is still reported, with how far
    $this->assertEqualsWithDelta(300, $airports->at(45.5887 + 2800 / 111195, -122.5980)[2], 20);
    // Downtown Portland and the middle of the Pacific are at no airport
    $this->assertNull($airports->at(45.5152, -122.6784));
    $this->assertNull($airports->at(30.0, -150.0));
    // Near the pole, where a degree of longitude is short
    $lyr = $airports->at(78.2461, 15.4656);
    $this->assertSame(['LYR', 'Svalbard Airport, Longyear'], [$lyr[0], $lyr[1]]);
  }

  public function testTheListIsWellFormed() {
    $lines = file(self::FILE, FILE_IGNORE_NEW_LINES);
    $this->assertSame('iata,size,lat,lng,name', array_shift($lines));
    $codes = [];
    foreach($lines as $line) {
      $row = explode(',', $line, 5);
      $this->assertCount(5, $row);
      $this->assertMatchesRegularExpression('/^[A-Z0-9]{3}$/', $row[0]);
      $this->assertArrayHasKey($row[1], Airports::RADIUS);
      $this->assertTrue(is_numeric($row[2]) && is_numeric($row[3]) && abs($row[2]) <= 90 && abs($row[3]) <= 180);
      $this->assertNotSame('', $row[4]);
      $codes[$row[0]] = true;
    }
    $this->assertGreaterThan(9000, count($codes));
    $this->assertSame(count($codes), count($lines), 'no code twice');
  }

}
