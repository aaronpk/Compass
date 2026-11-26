<?php
namespace App\Geofence;

use DateTime;
use DateTimeZone;

// How places and their events are worded on the Places pages, in the
// database's own timezone and units
class Text {

  public function __construct(private object $database) {}

  public function when(?string $date): string {
    if(!$date)
      return '';
    $d = new DateTime($date, new DateTimeZone('UTC'));
    try {
      $d->setTimezone(new DateTimeZone($this->database->timezone));
    } catch(\Exception $e) {
      // An unknown timezone name: show UTC
    }
    return $d->format('M j, g:i a');
  }

  public function distance(int $metres): string {
    if($this->database->metric)
      return $metres >= 1000 ? round($metres / 1000, 1).' km' : $metres.' m';
    return $metres >= 1609 ? round($metres / 1609.344, 1).' mi' : (round($metres * 3.28084 / 10) * 10).' ft';
  }

  public function kind(object $fence): string {
    return match($fence->kind) {
      'circle' => 'Circle, '.$this->distance((int)$fence->radius),
      'polygon' => 'Shape',
      default => 'The '.$fence->place_level.' you are in',
    };
  }

  // Where the device is in relation to the place
  public function status(object $fence): string {
    $since = $fence->state_since ? ' since '.$this->when($fence->state_since) : '';
    if($fence->kind == 'place')
      return $fence->value_label ? $fence->value_label.$since : 'Not known yet';
    return $fence->state == 'unknown' ? 'Not known yet' : ucfirst($fence->state).$since;
  }

  public function event(object $event): string {
    return match($event->type) {
      'enter' => 'Arrived',
      'exit' => 'Left',
      'cross' => 'Passed through',
      default => ($event->from_label ?: '?').' → '.($event->to_label ?: '?'),
    };
  }

  public function delivery(object $delivery): string {
    return match($delivery->status) {
      'pending' => 'Sending',
      'sent' => 'Sent',
      'skipped' => 'Too late, not sent',
      default => 'Failed'.($delivery->response_code ? ', answered '.$delivery->response_code : ''),
    };
  }

  // How an event's web hooks went, as [[text, failed?], ...] with the same
  // outcome counted once: "Sent (2)", "Too late, not sent"
  public function deliveries(iterable $deliveries): array {
    $counts = [];
    foreach($deliveries as $delivery) {
      $text = $this->delivery($delivery);
      $counts[$text] ??= [0, $delivery->status == 'failed'];
      $counts[$text][0]++;
    }
    $out = [];
    foreach($counts as $text => [$n, $failed])
      $out[] = [$n > 1 ? "$text ($n)" : $text, $failed];
    return $out;
  }

  // What a suggested place (see Suggester) is called until someone names it
  public function suggestedName(array $spot): string {
    $in = empty($spot['city']) ? '' : ' in '.$spot['city'];
    return match($spot['kind']) {
      'home' => 'Home',
      'work' => 'Work',
      // A month of nights is more than a visit
      'overnight' => ($spot['nights'] >= 30 ? 'Home' : 'Overnight stay').$in,
      default => 'Place'.$in,
    };
  }

  public function suggestedKind(array $spot): string {
    return ['home' => 'Home', 'work' => 'Work', 'overnight' => 'Overnight', 'frequent' => 'Frequent'][$spot['kind']];
  }

  // Why a spot is suggested: "142 nights and 2,310 hours on 300 days"
  public function evidence(array $spot): string {
    $count = fn(int $n, string $one, string $many) => number_format($n).' '.($n == 1 ? $one : $many);
    $parts = [];
    if($spot['nights'] > 0)
      $parts[] = $count($spot['nights'], 'night', 'nights');
    $parts[] = $count($spot['hours'], 'hour', 'hours');
    return implode(' and ', $parts).' on '.$count($spot['days'], 'day', 'days');
  }

  // When that was: "Mar 2 to Nov 24, 2026", in the dates the stops fell on
  public function between(array $spot): string {
    $first = new DateTime($spot['first']);
    $last = new DateTime($spot['last']);
    if($spot['first'] == $spot['last'])
      return $last->format('M j, Y');
    return $first->format($first->format('Y') == $last->format('Y') ? 'M j' : 'M j, Y').' to '.$last->format('M j, Y');
  }

  // Which events a web hook is sent for
  public function hook(object $hook, object $fence): string {
    if($fence->kind == 'place')
      return 'When the '.$fence->place_level.' changes';
    $parts = array_keys(array_filter(['arrive' => $hook->on_enter, 'leave' => $hook->on_exit, 'pass through' => $hook->on_cross]));
    return $parts ? 'When I '.implode(' or ', $parts) : 'Never';
  }

}
