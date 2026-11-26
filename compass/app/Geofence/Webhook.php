<?php
namespace App\Geofence;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The web hooks sent for geofence events. A place can have several, each
// for the kinds of event it chooses.
//
// The body is JSON. It is signed with the database's web hook secret:
//   Compass-Signature: sha256=<hex HMAC-SHA256 of the raw body>
// so the receiver can tell it came from this server. Unlike the older "ping"
// web hooks, no token that gives access to the database is sent.
class Webhook {

  const TIMEOUT = 10;

  // Does this web hook (a geofence_webhooks row) want this kind of event?
  // A change of city, region or country is the only event its place has.
  public static function wants(object $hook, string $type): bool {
    return match($type) {
      'enter' => (bool)$hook->on_enter,
      'exit' => (bool)$hook->on_exit,
      'cross' => (bool)$hook->on_cross,
      default => true,
    };
  }

  public static function payload(object $event, object $fence, object $database): array {
    $payload = [
      'event' => $event->type,
      'id' => (int)$event->id,
      'time' => gmdate('Y-m-d\TH:i:s\Z', strtotime($event->event_at.' UTC')),
      'delayed' => (bool)$event->delayed,
      'database' => $database->name,
      'fence' => ['id' => (int)$fence->id, 'name' => $fence->name, 'kind' => $fence->kind],
      'location' => [
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [(float)$event->lng, (float)$event->lat]],
        'properties' => array_filter(['horizontal_accuracy' => $event->accuracy === null ? null : (int)$event->accuracy], fn($v) => $v !== null) ?: new \stdClass,
      ],
    ];
    if($fence->kind == 'place') {
      $payload['fence']['level'] = $fence->place_level;
      $payload['from'] = ['code' => $event->from_value, 'name' => $event->from_label];
      $payload['to'] = ['code' => $event->to_value, 'name' => $event->to_label];
    }
    return $payload;
  }

  // Posts the payload. Returns the HTTP status, or 0 when there was no answer.
  public static function send(string $url, array $payload, string $secret): int {
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
      $response = (new Client(['timeout' => self::TIMEOUT, 'connect_timeout' => 5, 'http_errors' => false, 'allow_redirects' => false]))
        ->post($url, [
          'headers' => [
            'Content-Type' => 'application/json',
            'User-Agent' => 'Compass',
            'Compass-Event' => $payload['event'],
            'Compass-Event-Id' => (string)$payload['id'],
            'Compass-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
          ],
          'body' => $body,
        ]);
      return $response->getStatusCode();
    } catch(\Throwable $e) {
      return 0;
    }
  }

  // The database's signing secret, made the first time it is needed
  public static function secret(object $database): string {
    if(!empty($database->webhook_secret))
      return $database->webhook_secret;
    DB::table('databases')->where('id', $database->id)->whereNull('webhook_secret')->update(['webhook_secret' => Str::random(40)]);
    return DB::table('databases')->where('id', $database->id)->value('webhook_secret');
  }

  public static function validUrl($url): bool {
    return is_string($url) && strlen($url) <= 1024 && preg_match('#^https?://[^\s/]+#i', $url) && filter_var($url, FILTER_VALIDATE_URL);
  }

}
