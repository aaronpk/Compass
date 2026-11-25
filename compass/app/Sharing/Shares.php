<?php
namespace App\Sharing;

use Illuminate\Support\Facades\DB;

// Share links: who may see them and what a viewer is given
class Shares {

  // The share for a token, unless it has expired or been stopped
  public static function active(?string $token): ?object {
    if(!$token)
      return null;
    return self::activeQuery()->where('token', $token)->first();
  }

  public static function activeFor(int $databaseId) {
    return self::activeQuery()->where('database_id', $databaseId)->get();
  }

  private static function activeQuery() {
    return DB::table('shares')->whereNull('revoked_at')
      ->where(function($q) {
        // No expiry date means shared until stopped
        $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
      });
  }

  // What a viewer may see of a stored location: where, when, how fast and
  // how accurate. Nothing else on the record (battery, wifi name, device id)
  // leaves the server.
  public static function reduce($feature): ?array {
    $feature = json_decode(json_encode($feature), true);
    $coordinates = $feature['geometry']['coordinates'] ?? null;
    if(!is_array($coordinates) || !isset($coordinates[0], $coordinates[1]))
      return null;
    $properties = [];
    foreach(['timestamp', 'speed', 'horizontal_accuracy'] as $key) {
      if(isset($feature['properties'][$key]) && is_scalar($feature['properties'][$key]))
        $properties[$key] = $feature['properties'][$key];
    }
    return [
      'type' => 'Feature',
      'geometry' => ['type' => 'Point', 'coordinates' => [(float)$coordinates[0], (float)$coordinates[1]]],
      'properties' => $properties,
    ];
  }

  // When the shared track begins: when the share was created, or the start
  // of the trip in progress if that is earlier
  public static function trackStart(object $share, object $database): int {
    $start = strtotime($share->created_at.' UTC');
    $trip = $database->current_trip ? json_decode($database->current_trip, true) : null;
    if(is_array($trip) && !empty($trip['start']) && ($tripStart = strtotime((string)$trip['start'])))
      $start = min($start, $tripStart);
    return $start;
  }

}
