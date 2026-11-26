<?php
namespace Tests\Unit\Geofence;

use App\Geofence\Evaluator;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Stats\TrackBuilder;

class EvaluatorTest extends TestCase {

  const START = '2026-01-10 08:00:00';

  private static function home(): array {
    return ['kind' => 'circle', 'lat' => ShapesTest::LAT, 'lng' => ShapesTest::LNG, 'radius' => 150];
  }

  private static function track(float $north, float $east): TrackBuilder {
    return new TrackBuilder(self::START, ...ShapesTest::at($north, $east));
  }

  // Runs one fence over a track and returns [state, events]
  private static function fence(array $points, string $state, ?array $fence = null, ?array $previous = null): array {
    [$states, $events] = Evaluator::run([1 => $fence ?? self::home()], [1 => ['state' => $state]], $points, $previous);
    return [$states[1], $events];
  }

  private static function types(array $events): array {
    return array_column($events, 'type');
  }

  public function testArrivingAndStayingIsOneEnter() {
    $track = self::track(0, -2000)->travel(ShapesTest::LAT, ShapesTest::LNG, 300)->stay(600);
    [$state, $events] = self::fence($track->points, 'outside');
    $this->assertSame(['enter'], self::types($events));
    $this->assertSame('inside', $state['state']);
    // Timed at the first point that was inside, not at the confirming one
    $this->assertSame($state['since'], $events[0]['t']);
    $this->assertLessThan(strtotime(self::START.' UTC') + 300, $events[0]['t']);
    $this->assertNull($state['candidate']);
  }

  public function testLeavingIsOneExitOnceBeyondTheMargin() {
    $track = self::track(0, 0)->stay(300)->go(2000, 90, 300);
    [$state, $events] = self::fence($track->points, 'inside');
    $this->assertSame(['exit'], self::types($events));
    $this->assertSame('outside', $state['state']);
    // 150 m radius, 30 m margin, 10 m accuracy: not before 190 m from the centre
    $metres = \App\Stats\Geo\Geo::distance(ShapesTest::LAT, ShapesTest::LNG, $events[0]['lat'], $events[0]['lng']);
    $this->assertGreaterThan(190, $metres);
    $this->assertLessThan(240, $metres);
  }

  public function testSittingOnTheEdgeDoesNothing() {
    // 20 m of scatter around a spot 10 m outside the edge, for an hour
    foreach(['inside', 'outside'] as $state) {
      $track = self::track(0, 160)->stay(3600, 20);
      [$after, $events] = self::fence($track->points, $state);
      $this->assertSame([], $events, "starting $state");
      $this->assertSame($state, $after['state']);
    }
  }

  public function testAPointWhoseAccuracyCircleStraddlesTheEdgeSaysNothing() {
    // 100 m beyond the edge but only accurate to 150 m: can't be sure it's out
    $track = self::track(0, 250)->stay(600, 5, 5, 150);
    [$state, $events] = self::fence($track->points, 'inside');
    $this->assertSame([], $events);
    $this->assertSame('inside', $state['state']);
    // 100 m inside but only accurate to 150 m, with half the size counted: can't be sure it's in
    $track = self::track(0, 100)->stay(600, 5, 5, 150);
    [$state, $events] = self::fence($track->points, 'outside');
    $this->assertSame([], $events);
  }

  public function testAVeryInaccuratePointIsIgnored() {
    $this->assertNull(Evaluator::where(self::home(), 150, ShapesTest::LAT, ShapesTest::LNG, 1500));
    $this->assertSame('inside', Evaluator::where(self::home(), 150, ShapesTest::LAT, ShapesTest::LNG, 65));
  }

  public function testDrivingStraightThroughIsACrossNotAVisit() {
    // 30 m/s through the middle, a point every 5 s: two points land inside, 5 s apart
    $track = self::track(0, -1500)->go(3000, 90, 100);
    [$state, $events] = self::fence($track->points, 'outside');
    $this->assertSame(['cross'], self::types($events));
    $this->assertSame('outside', $state['state']);
  }

  public function testALineThroughTheFenceWithNoPointInsideIsACross() {
    // A point either side, 40 s apart
    $track = self::track(0, -600)->stay(20)->go(1200, 90, 40, 40)->stay(20);
    [$state, $events] = self::fence($track->points, 'outside');
    $this->assertSame(['cross'], self::types($events));
    $this->assertSame('outside', $state['state']);
  }

  public function testPointsFarApartInTimeDontDrawALine() {
    $track = self::track(0, -600)->stay(20)->gap(3600, ...ShapesTest::at(0, 600))->stay(20);
    [, $events] = self::fence($track->points, 'outside');
    $this->assertSame([], $events);
  }

  public function testADeviceThatReportsRarelyIsTakenAtItsWord() {
    // One point every five minutes: waiting for a second would take too long
    $track = self::track(0, -3000)->stay(0)->gap(300, ShapesTest::LAT, ShapesTest::LNG)->stay(0);
    [$state, $events] = self::fence($track->points, 'outside');
    $this->assertSame(['enter'], self::types($events));
    $this->assertSame('inside', $state['state']);
  }

  public function testOnePointOutsideDoesntCountAsLeaving() {
    $track = self::track(0, 0)->stay(60)->glitch(400)->stay(60);
    [$state, $events] = self::fence($track->points, 'inside');
    $this->assertSame([], $events);
    $this->assertSame('inside', $state['state']);
    $this->assertNull($state['candidate']);
  }

  public function testANewFenceSettlesWithoutAnEvent() {
    $track = self::track(0, 0)->stay(120);
    [$state, $events] = self::fence($track->points, 'unknown');
    $this->assertSame([], $events);
    $this->assertSame('inside', $state['state']);
    // And what happens next is an event
    [$state, $events] = self::fence(self::track(0, 0)->gap(200)->go(2000, 0, 300)->points, 'inside');
    $this->assertSame(['exit'], self::types($events));
  }

  public function testAChangeIsCarriedBetweenBatches() {
    // The first agreeing point arrives in one request, the second in the next
    $track = self::track(0, -2000)->travel(ShapesTest::LAT, ShapesTest::LNG, 300)->stay(600);
    $points = $track->points;
    $first = null;
    foreach($points as $i => $point) {
      if(Evaluator::where(self::home(), 150, $point[1], $point[2], 10) == 'inside') { $first = $i; break; }
    }
    [$states, $events] = Evaluator::run([1 => self::home()], [1 => ['state' => 'outside']], array_slice($points, 0, $first + 1), null);
    $this->assertSame([], $events);
    $this->assertSame('inside', $states[1]['candidate']['to']);
    $last = $points[$first];
    // The state goes through JSON on its way to the database and back
    $states = json_decode(json_encode($states), true);
    [$states, $events] = Evaluator::run([1 => self::home()], $states, array_slice($points, $first + 1), [$last[0], $last[1], $last[2], $last[3]]);
    $this->assertSame(['enter'], self::types($events));
    $this->assertSame($last[0], (float)$events[0]['t']);
  }

  public function testWalkingIntoAHoleIsLeaving() {
    $ring = ShapesTest::square(400, 150);
    $track = self::track(0, 280)->stay(120)->travel(ShapesTest::LAT, ShapesTest::LNG, 300, 5, 'walking')->stay(120);
    [$state, $events] = self::fence($track->points, 'unknown', $ring);
    $this->assertSame(['exit'], self::types($events));
    $this->assertSame('outside', $state['state']);
  }

  public function testSeveralFencesAreKeptApart() {
    $work = ['kind' => 'circle', 'lat' => ShapesTest::at(0, 5000)[0], 'lng' => ShapesTest::at(0, 5000)[1], 'radius' => 200];
    $track = self::track(0, 0)->stay(120)->travel($work['lat'], $work['lng'], 600)->stay(120);
    [$states, $events] = Evaluator::run(['home' => self::home(), 'work' => $work], ['home' => ['state' => 'inside'], 'work' => ['state' => 'outside']], $track->points, null);
    $this->assertSame([['home', 'exit'], ['work', 'enter']], array_map(fn($e) => [$e['fence'], $e['type']], $events));
    $this->assertSame('outside', $states['home']['state']);
    $this->assertSame('inside', $states['work']['state']);
  }

  ////////////////////////////////////////////////////////////
  // Choosing which points to look at

  public function testTheSamePointsHandedInTwiceAreIgnoredTheSecondTime() {
    $points = self::track(0, 0)->stay(60)->points;
    $now = end($points)[0] + 1;
    [$accepted, $cursor] = Evaluator::accept($points, null, $now);
    $this->assertCount(count($points), $accepted);
    [$again] = Evaluator::accept($points, $cursor, $now);
    $this->assertSame([], $again);
  }

  public function testAnOldPointArrivingLateIsIgnored() {
    $points = self::track(0, 0)->stay(60)->points;
    $now = end($points)[0] + 1;
    [, $cursor] = Evaluator::accept($points, null, $now);
    $old = $points[0];
    $old[0] -= 3600;
    [$accepted, $after] = Evaluator::accept([$old], $cursor, $now);
    $this->assertSame([], $accepted);
    $this->assertSame($cursor, $after);
  }

  public function testAPointFromTheFutureDoesntBlockWhatFollows() {
    $points = self::track(0, 0)->stay(60)->points;
    $now = end($points)[0] + 1;
    $broken = $points[3];
    $broken[0] += 86400 * 365;
    [$accepted, $cursor] = Evaluator::accept([...$points, $broken], null, $now);
    $this->assertCount(count($points), $accepted);
    $this->assertSame(end($points)[0], $cursor['seen']);
  }

  public function testAJumpIsDropped() {
    $track = self::track(0, 0)->stay(30);
    $before = count($track->points);
    $track->glitch(20000)->stay(30);
    $points = $track->points;
    [$accepted] = Evaluator::accept($points, null, end($points)[0] + 1);
    $this->assertCount(count($points) - 1, $accepted);
    $this->assertNotContains($points[$before], $accepted);
  }

  public function testAWrongStartingPointDoesntBlockTheRealOnes() {
    // The last accepted point was the glitch; the real track follows
    $points = self::track(0, 0)->stay(30)->points;
    [$glitchLat, $glitchLng] = ShapesTest::at(20000, 0);
    $cursor = ['seen' => $points[0][0] - 1, 'last' => [$points[0][0] - 1, $glitchLat, $glitchLng, 10], 'rejected' => null];
    [$accepted, $after] = Evaluator::accept($points, $cursor, end($points)[0] + 1);
    // The first real point looks like a jump; the second agrees with it
    $this->assertCount(count($points) - 1, $accepted);
    $this->assertNull($after['rejected']);
  }

  public function testMarginGrowsWithTheFenceBetweenItsLimits() {
    $this->assertSame(25.0, (float)Evaluator::margin(50));
    $this->assertSame(40.0, (float)Evaluator::margin(200));
    $this->assertSame(100.0, (float)Evaluator::margin(5000));
  }

}
