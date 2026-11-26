<?php
namespace Tests\Unit\Geofence;

use App\Geofence\Suggester;
use DateTime;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class SuggesterTest extends TestCase {

  const TZ = 'America/Los_Angeles';

  // Spots around the test position, as metres north and east of it
  const HOME = [0, 0];
  const OFFICE = [3000, 4000];
  const CAFE = [-800, 1500];

  // One stop: at a spot from a local time for a number of hours
  private static function stay(array $spot, string $local, float $hours, string $tz = self::TZ, ?int $city = 1): array {
    [$lat, $lng] = ShapesTest::at($spot[0], $spot[1]);
    $start = (new DateTime($local, new DateTimeZone($tz)))->getTimestamp();
    return [(float)$start, $start + $hours * 3600, $lat, $lng, $tz, $city];
  }

  // The same stop on a run of days. $only picks weekdays (1 = Monday).
  private static function daily(array $spot, string $first, string $time, float $hours, int $days, ?array $only = null, string $tz = self::TZ): array {
    $stops = [];
    $day = new DateTime($first, new DateTimeZone($tz));
    for($i = 0; $i < $days; $i++) {
      if(!$only || in_array((int)$day->format('N'), $only))
        $stops[] = self::stay($spot, $day->format('Y-m-d').' '.$time, $hours, $tz);
      $day->modify('+1 day');
    }
    return $stops;
  }

  // Cuts stops at UTC midnight, as the stats tables store them
  private static function cut(array $stops): array {
    $out = [];
    foreach($stops as $stop) {
      while(floor($stop[0] / 86400) < floor(($stop[1] - 1) / 86400)) {
        $midnight = (floor($stop[0] / 86400) + 1) * 86400;
        $out[] = [$stop[0], $midnight] + $stop;
        $stop[0] = $midnight;
      }
      $out[] = $stop;
    }
    return $out;
  }

  // Sixty nights at home, working days at the office (2026-03-02 is a Monday)
  private static function routine(): array {
    return self::cut(array_merge(
      self::daily(self::HOME, '2026-03-02', '19:00', 12.5, 60),
      self::daily(self::OFFICE, '2026-03-02', '08:30', 9, 60, [1, 2, 3, 4, 5]),
    ));
  }

  private static function kinds(array $result): array {
    return array_column($result['suggestions'], 'kind');
  }

  public function testHomeAndWorkAreFoundFromNightsAndWorkingHours() {
    $result = Suggester::find(self::routine());
    $this->assertSame(['home', 'work'], self::kinds($result));
    [$home, $work] = $result['suggestions'];

    $this->assertSame(60, $home['nights']);
    $this->assertSame(750, $home['hours']);
    $this->assertSame(61, $home['days']);   // each stay runs into the next morning
    $this->assertSame('2026-03-02', $home['first']);
    $this->assertSame('2026-05-01', $home['last']);
    [$lat, $lng] = ShapesTest::at(...self::HOME);
    $this->assertEqualsWithDelta($lat, $home['lat'], 0.00002);
    $this->assertEqualsWithDelta($lng, $home['lng'], 0.00002);

    // 44 weekdays, 8 of the 9 hours fall between 9 and 5
    $this->assertSame(0, $work['nights']);
    $this->assertSame(44, $work['days']);
    $this->assertSame(396, $work['hours']);
    $this->assertSame(352, $work['work_hours']);
  }

  public function testANightCutAtUtcMidnightIsStillOneNight() {
    // In Berlin, 00:00 UTC is one or two in the morning, so every night is two rows
    $whole = self::daily(self::HOME, '2026-03-02', '22:00', 9, 10, null, 'Europe/Berlin');
    $cut = self::cut($whole);
    $this->assertCount(20, $cut);
    $this->assertSame(10, Suggester::find($cut)['suggestions'][0]['nights']);
    $this->assertSame(10, Suggester::find($whole)['suggestions'][0]['nights']);
  }

  public function testNightsAreCountedOnTheLocalClock() {
    // Asleep in Tokyo from 23:00 to 07:00 is the middle of the day in UTC
    $tokyo = Suggester::find(self::cut(self::daily(self::HOME, '2026-03-02', '23:00', 8, 10, null, 'Asia/Tokyo')));
    $this->assertSame(10, $tokyo['suggestions'][0]['nights']);
    $this->assertSame('home', $tokyo['suggestions'][0]['kind']);
    // The same hours on a Pacific clock are an afternoon, not a night
    $afternoon = Suggester::find(self::cut(self::daily(self::HOME, '2026-03-02', '13:00', 8, 20)));
    $this->assertSame(0, $afternoon['suggestions'][0]['nights']);
  }

  public function testEveningsAreNotNights() {
    // Home by 6 pm, out again at 1 am, every day for a month
    $result = Suggester::find(self::cut(self::daily(self::HOME, '2026-03-02', '18:00', 7, 30)));
    $this->assertSame(0, $result['suggestions'][0]['nights']);
    $this->assertSame('frequent', $result['suggestions'][0]['kind']);
  }

  public function testOneVisitHoweverLongIsNotAPlace() {
    // A weekend away: three nights, sixty hours, four calendar days
    $hotel = self::cut([self::stay(self::CAFE, '2026-03-06 18:00', 60)]);
    $this->assertSame([], Suggester::find($hotel)['suggestions']);
    // A week of days at a conference
    $this->assertSame([], Suggester::find(self::daily(self::CAFE, '2026-03-02', '08:00', 10, 5))['suggestions']);
    // The same five days spread over five weeks are somewhere you keep going back to
    $weekly = Suggester::find(self::daily(self::CAFE, '2026-03-02', '08:00', 10, 35, [1]));
    $this->assertSame(['work'], self::kinds($weekly));
  }

  public function testFiveNightsElsewhereIsAnOvernightStay() {
    $stops = array_merge(self::routine(), self::cut(self::daily(self::CAFE, '2026-06-01', '21:00', 10, 5)));
    $result = Suggester::find($stops);
    $this->assertSame(['home', 'work', 'overnight'], self::kinds($result));
    $this->assertSame(5, $result['suggestions'][2]['nights']);
  }

  public function testARegularHauntIsSuggestedOnceItAddsUp() {
    // An hour at the café: three visits is nothing, twelve is a habit
    $few = array_merge(self::routine(), self::daily(self::CAFE, '2026-03-07', '10:00', 1, 3));
    $this->assertSame(['home', 'work'], self::kinds(Suggester::find($few)));
    $many = array_merge(self::routine(), self::daily(self::CAFE, '2026-03-07', '10:00', 1, 84, [6]));
    $result = Suggester::find($many);
    $this->assertSame(['home', 'work', 'frequent'], self::kinds($result));
    $this->assertSame(12, $result['suggestions'][2]['days']);
  }

  public function testWorkingFromHomeGivesOnlyHome() {
    $result = Suggester::find(self::cut(self::daily(self::HOME, '2026-03-02', '00:00', 24, 30)));
    $this->assertSame(['home'], self::kinds($result));
  }

  public function testAnEarlierHomeIsNotMistakenForWork() {
    // Fifty days living and working from one address, then sixty nights at a
    // new home with one day a week at an office
    $stops = self::cut(array_merge(
      self::daily(self::CAFE, '2026-01-05', '00:00', 24, 50),
      self::daily(self::HOME, '2026-03-02', '19:00', 12.5, 60),
      self::daily(self::OFFICE, '2026-03-02', '08:30', 9, 60, [1]),
    ));
    $result = Suggester::find($stops);
    $this->assertSame(['home', 'work', 'overnight'], self::kinds($result));
    [$home, $work, $earlier] = $result['suggestions'];
    $this->assertSame(60, $home['nights']);
    // Far more weekday hours were spent at the old address, but people slept there
    $this->assertSame(0, $work['nights']);
    $this->assertSame(50, $earlier['nights']);
    $this->assertGreaterThan(3 * $work['work_hours'], $earlier['work_hours']);
  }

  public function testStopsScatteredAroundOneSpotAreOneSuggestion() {
    $stops = [];
    foreach([[0, 0], [40, 30], [-35, 45], [50, -40], [-20, -55], [60, 10]] as $i => $offset)
      $stops = array_merge($stops, self::daily($offset, '2026-03-'.sprintf('%02d', 2 + $i), '22:00', 9, 1));
    $result = Suggester::find(self::cut($stops));
    $this->assertCount(1, $result['suggestions']);
    $this->assertSame(6, $result['suggestions'][0]['nights']);
  }

  public function testSpotsThreeHundredMetresApartStaySeparate() {
    $stops = self::cut(array_merge(
      self::daily([0, 0], '2026-03-02', '22:00', 9, 10),
      self::daily([0, 300], '2026-04-02', '22:00', 9, 6),
    ));
    $result = Suggester::find($stops);
    $this->assertSame(['home', 'overnight'], self::kinds($result));
    $this->assertSame([10, 6], array_column($result['suggestions'], 'nights'));
  }

  public function testTheRadiusFollowsTheScatterWithinLimits() {
    $tight = Suggester::find(self::cut(self::daily(self::HOME, '2026-03-02', '22:00', 9, 10)));
    $this->assertSame(75, $tight['suggestions'][0]['radius']);

    $stops = [];
    foreach([[0, 0], [0, 0], [0, 0], [0, 0], [80, 0], [0, 80], [-80, 0], [0, -80], [60, 60], [-60, -60]] as $i => $offset)
      $stops = array_merge($stops, self::daily($offset, '2026-03-'.sprintf('%02d', 2 + $i), '22:00', 9, 1));
    $loose = Suggester::find(self::cut($stops))['suggestions'][0];
    $this->assertGreaterThan(100, $loose['radius']);
    $this->assertLessThanOrEqual(Suggester::MAX_RADIUS, $loose['radius']);
    $this->assertSame(0, $loose['radius'] % 25);
  }

  public function testASpotThatIsAlreadyAPlaceIsNotSuggested() {
    [$lat, $lng] = ShapesTest::at(20, 20);
    $home = ['kind' => 'circle', 'lat' => $lat, 'lng' => $lng, 'radius' => 150];
    $result = Suggester::find(self::routine(), [$home]);
    // The office is still work: what the spots are is decided before any are left out
    $this->assertSame(['work'], self::kinds($result));
    // A place somewhere else changes nothing
    [$lat, $lng] = ShapesTest::at(50000, 0);
    $this->assertSame(['home', 'work'], self::kinds(Suggester::find(self::routine(), [['kind' => 'circle', 'lat' => $lat, 'lng' => $lng, 'radius' => 150]])));
  }

  public function testADismissedSpotIsLeftOutAndReported() {
    [$lat, $lng] = ShapesTest::at(3020, 4010);
    [$farLat, $farLng] = ShapesTest::at(90000, 0);
    $result = Suggester::find(self::routine(), [], [7 => [$lat, $lng], 8 => [$farLat, $farLng]]);
    $this->assertSame(['home'], self::kinds($result));
    $this->assertSame('work', $result['dismissed'][7]['kind']);
    $this->assertSame(396, $result['dismissed'][7]['hours']);
    // Nothing is found there in this stretch of time
    $this->assertNull($result['dismissed'][8]);
  }

  public function testTheCityIsTheOneMostTimeWasSpentIn() {
    $stops = self::daily(self::HOME, '2026-03-02', '22:00', 9, 6);
    $stops[0][5] = 99;
    $stops[1][5] = null;
    $this->assertSame(1, Suggester::find(self::cut($stops))['suggestions'][0]['city_id']);
  }

  public function testManyStopsAreHandledQuickly() {
    // Ten years of a routine plus ten thousand one-off stops all over a city
    $stops = [];
    for($year = 2016; $year < 2026; $year++)
      $stops = array_merge($stops, self::daily(self::HOME, "$year-01-01", '19:00', 12.5, 365), self::daily(self::OFFICE, "$year-01-01", '08:30', 9, 365, [1, 2, 3, 4, 5]));
    $seed = 7;
    for($i = 0; $i < 10000; $i++) {
      $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
      $north = ($seed % 40000) - 20000;
      $seed = ($seed * 1103515245 + 12345) & 0x7fffffff;
      $stops[] = self::stay([$north, ($seed % 40000) - 20000], '2025-06-01 12:00', 0.5);
    }
    $started = microtime(true);
    $result = Suggester::find(self::cut($stops));
    $this->assertLessThan(5, microtime(true) - $started);
    $this->assertSame(['home', 'work'], array_slice(self::kinds($result), 0, 2));
    $this->assertSame(3650, $result['suggestions'][0]['nights']);
  }

}
