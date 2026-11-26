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
      default => $fence->place_level == 'airport' ? 'The airport you are at' : 'The '.$fence->place_level.' you are in',
    };
  }

  // Where the device is in relation to the place
  public function status(object $fence): string {
    $since = $fence->state_since ? ' since '.$this->when($fence->state_since) : '';
    if($fence->kind == 'place' && $fence->place_level == 'airport' && $fence->value === '')
      return 'Not at an airport'.$since;
    if($fence->kind == 'place' && $fence->place_level == 'airport' && $fence->value_label)
      return self::airport($fence->value, $fence->value_label).$since;
    if($fence->kind == 'place')
      return $fence->value_label ? $fence->value_label.$since : 'Not known yet';
    return $fence->state == 'unknown' ? 'Not known yet' : ucfirst($fence->state).$since;
  }

  // "PDX (Portland International Airport)"
  public static function airport(?string $code, string $name): string {
    return $code ? $code.' ('.$name.')' : $name;
  }

  // The day and the time of day, for the two columns of the history
  public function day(string $date): string {
    return $this->local($date)->format('D, M j');
  }

  public function time(string $date): string {
    return $this->local($date)->format('g:i a');
  }

  private function local(string $date): DateTime {
    $d = new DateTime($date, new DateTimeZone('UTC'));
    try {
      $d->setTimezone(new DateTimeZone($this->database->timezone));
    } catch(\Exception $e) {
      // An unknown timezone name: show UTC
    }
    return $d;
  }

  public function event(object $event): string {
    return match($event->type) {
      'enter' => 'Arrived',
      'exit' => 'Left',
      'cross' => 'Passed through',
      default => 'Changed',
    };
  }

  // Which airport, or which city, region or country it changed from and to.
  // Empty for a place of your own: the place is the answer.
  public function where(object $event): string {
    return match(true) {
      $event->type == 'change' => ($event->from_label ?: '?').' → '.($event->to_label ?: '?'),
      $event->type == 'enter' && $event->to_label => self::airport($event->to_value, $event->to_label),
      $event->type == 'exit' && $event->from_label => self::airport($event->from_value, $event->from_label),
      default => '',
    };
  }

  // How long the stay that this event ended was: "2 h 15 min"
  public function stayed(object $event): string {
    if(!in_array($event->type, ['exit', 'change']) || empty($event->previous_at))
      return '';
    $minutes = (int)round((strtotime($event->event_at) - strtotime($event->previous_at)) / 60);
    if($minutes < 1)
      return 'under a minute';
    if($minutes < 60)
      return $minutes.' min';
    if($minutes < 48 * 60)
      return intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : '');
    $hours = (int)round($minutes / 60);
    return intdiv($hours, 24).' d'.($hours % 24 ? ' '.($hours % 24).' h' : '');
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
    if($fence->kind == 'place' && $fence->place_level != 'airport')
      return 'When the '.$fence->place_level.' changes';
    if($fence->kind == 'place')
      return match(true) {
        $hook->on_enter && $hook->on_exit => 'When I arrive at or leave an airport',
        (bool)$hook->on_enter => 'When I arrive at an airport',
        (bool)$hook->on_exit => 'When I leave an airport',
        default => 'Never',
      };
    $parts = array_keys(array_filter(['arrive' => $hook->on_enter, 'leave' => $hook->on_exit, 'pass through' => $hook->on_cross]));
    return $parts ? 'When I '.implode(' or ', $parts) : 'Never';
  }

}
