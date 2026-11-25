<?php
namespace Tests\Unit\Stats;

// Builds synthetic tracks in the shape PointReader returns:
// [time, lat, lng, accuracy, speed, altitude, motion]
class TrackBuilder {

  public array $points = [];
  public float $time;
  public float $lat;
  public float $lng;
  private int $seed;

  public function __construct(string $start, float $lat, float $lng, int $seed = 1) {
    $this->time = (float)strtotime($start.' UTC');
    $this->lat = $lat;
    $this->lng = $lng;
    $this->seed = $seed;
  }

  // Stay put, with each point scattered up to $jitter metres from the spot
  public function stay(int $seconds, float $jitter = 15, int $interval = 5, ?float $accuracy = 10): static {
    for($t = 0; $t <= $seconds; $t += $interval) {
      [$dLat, $dLng] = $this->offset($this->random() * $jitter, $this->random() * 360);
      $this->points[] = [$this->time + $t, $this->lat + $dLat, $this->lng + $dLng, $accuracy, 0.0, 30.0, 'stationary'];
    }
    $this->time += $seconds;
    return $this;
  }

  // Move in a straight line to a new position
  public function travel(float $lat, float $lng, int $seconds, int $interval = 5, ?string $motion = 'driving', ?float $accuracy = 10, float $altitude = 30, bool $speed = true): static {
    $metres = \App\Stats\Geo\Geo::distance($this->lat, $this->lng, $lat, $lng);
    for($t = $interval; $t <= $seconds; $t += $interval) {
      $f = $t / $seconds;
      $this->points[] = [
        $this->time + $t,
        $this->lat + ($lat - $this->lat) * $f,
        $this->lng + ($lng - $this->lng) * $f,
        $accuracy, $speed ? $metres / $seconds : null, $altitude, $motion,
      ];
    }
    $this->time += $seconds;
    $this->lat = $lat;
    $this->lng = $lng;
    return $this;
  }

  // Travel a distance in metres on a compass bearing
  public function go(float $metres, float $bearing, int $seconds, int $interval = 5, ?string $motion = 'driving', ?float $accuracy = 10): static {
    [$dLat, $dLng] = $this->offset($metres, $bearing);
    return $this->travel($this->lat + $dLat, $this->lng + $dLng, $seconds, $interval, $motion, $accuracy);
  }

  // No points for a while; optionally reappear somewhere else
  public function gap(int $seconds, ?float $lat = null, ?float $lng = null): static {
    $this->time += $seconds;
    if($lat !== null) {
      $this->lat = $lat;
      $this->lng = $lng;
    }
    return $this;
  }

  // One point far from the track, as a GPS glitch
  public function glitch(float $metres): static {
    [$dLat, $dLng] = $this->offset($metres, 45);
    $this->points[] = [$this->time + 1, $this->lat + $dLat, $this->lng + $dLng, 10.0, 0.0, 30.0, null];
    $this->time += 2;
    return $this;
  }

  public function trip(string $mode, float $start, ?float $distance = null, ?array $from = null): array {
    return ['start' => $start, 'end' => $this->time, 'mode' => $mode, 'distance' => $distance, 'from' => $from, 'to' => [$this->lat, $this->lng]];
  }

  private function offset(float $metres, float $bearing): array {
    $dLat = $metres * cos(deg2rad($bearing)) / 111195;
    $dLng = $metres * sin(deg2rad($bearing)) / (111195 * cos(deg2rad($this->lat)));
    return [$dLat, $dLng];
  }

  // Small repeatable generator, so tests don't depend on PHP's random source
  private function random(): float {
    $this->seed = ($this->seed * 1103515245 + 12345) & 0x7fffffff;
    return $this->seed / 0x7fffffff;
  }

}
