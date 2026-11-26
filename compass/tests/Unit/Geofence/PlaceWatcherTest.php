<?php
namespace Tests\Unit\Geofence;

use App\Geofence\PlaceWatcher;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Stats\TrackBuilder;

class PlaceWatcherTest extends TestCase {

  const START = '2026-01-10 08:00:00';

  public int $lookups = 0;

  // West of the test spot is Westland, east of it Eastland, with the sea 20 km out
  private function watcher(): PlaceWatcher {
    return new PlaceWatcher(function(float $lat, float $lng) {
      $this->lookups++;
      $east = ($lng - ShapesTest::LNG) * 111195 * cos(deg2rad($lat));
      if(abs($east) > 20000)
        return ['country' => null, 'region' => null, 'city' => null];
      return $east < 0
        ? ['country' => ['WL', 'Westland'], 'region' => ['WL.01', 'West Province'], 'city' => ['100', 'Weston']]
        : ['country' => ['EL', 'Eastland'], 'region' => ['EL.07', 'East Province'], 'city' => null];
    });
  }

  private static function track(float $east): TrackBuilder {
    return new TrackBuilder(self::START, ...ShapesTest::at(0, $east));
  }

  const WATCHES = ['c' => 'country', 'r' => 'region', 't' => 'city'];
  const IN_WEST = [
    'c' => ['value' => 'WL', 'label' => 'Westland'],
    'r' => ['value' => 'WL.01', 'label' => 'West Province'],
    't' => ['value' => '100', 'label' => 'Weston'],
  ];

  public function testCrossingABorderChangesCountryAndRegionOnce() {
    $track = self::track(-5000)->stay(120)->go(10000, 90, 600)->stay(600);
    [$states, $events] = $this->watcher()->run(self::WATCHES, self::IN_WEST, $track->points, null);
    $this->assertSame(['c', 'r'], array_column($events, 'fence'));
    $this->assertSame(['WL', 'Westland'], $events[0]['from']);
    $this->assertSame(['EL', 'Eastland'], $events[0]['to']);
    $this->assertSame('EL.07', $states['r']['value']);
    // No city is listed to the east, so the last known one stands
    $this->assertSame('100', $states['t']['value']);
    // Timed at the first point over the border
    [, $borderLng] = ShapesTest::at(0, 0);
    $this->assertEqualsWithDelta($borderLng, $events[0]['lng'], 0.005);
  }

  public function testFindingOutWhereTheDeviceIsForTheFirstTimeIsntAChange() {
    $track = self::track(-5000)->stay(600);
    [$states, $events] = $this->watcher()->run(self::WATCHES, [], $track->points, null);
    $this->assertSame([], $events);
    $this->assertSame('Westland', $states['c']['label']);
    $this->assertNotNull($states['c']['since']);
  }

  public function testABriefHopOverTheBorderDoesNothing() {
    // A minute on the other side, then back
    $track = self::track(-200)->stay(120)->go(400, 90, 20)->stay(40)->go(400, 270, 20)->stay(300);
    [$states, $events] = $this->watcher()->run(self::WATCHES, self::IN_WEST, $track->points, null);
    $this->assertSame([], $events);
    $this->assertSame('WL', $states['c']['value']);
    $this->assertNull($states['c']['candidate']);
  }

  public function testOpenSeaLeavesTheLastPlaceStanding() {
    $track = self::track(-5000)->stay(120)->go(40000, 270, 1200)->stay(600);
    [$states, $events] = $this->watcher()->run(self::WATCHES, self::IN_WEST, $track->points, null);
    $this->assertSame([], $events);
    $this->assertSame('WL', $states['c']['value']);
  }

  public function testPlacesAreNotLookedUpForEveryPoint() {
    $track = self::track(-5000)->stay(3600, 15, 1);
    $this->watcher()->run(self::WATCHES, self::IN_WEST, $track->points, null);
    $this->assertCount(3601, $track->points);
    // The same few cells of 150 m, each looked up once
    $this->assertLessThan(6, $this->lookups);
  }

  public function testInaccuratePointsAreNotUsed() {
    $track = self::track(5000)->stay(600, 15, 5, 800);
    [, $events] = $this->watcher()->run(self::WATCHES, self::IN_WEST, $track->points, null);
    $this->assertSame([], $events);
    $this->assertSame(0, $this->lookups);
  }

  public function testARarelyReportingDeviceChangesOnOnePoint() {
    $track = self::track(-5000)->stay(0)->gap(1800, ...ShapesTest::at(0, 5000))->stay(0);
    [, $events] = $this->watcher()->run(['c' => 'country'], ['c' => self::IN_WEST['c']], $track->points, null);
    $this->assertCount(1, $events);
    $this->assertSame('change', $events[0]['type']);
  }

}
