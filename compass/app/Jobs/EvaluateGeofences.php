<?php
namespace App\Jobs;

use App\Geofence\Runner;

// Checks newly received points against a database's geofences, and queues
// the web hooks for whatever happened.
class EvaluateGeofences extends Job {

  // $points: [time, lat, lng, accuracy] lists, see Runner::point()
  public function __construct(private int $databaseId, private array $points) {}

  public function handle() {
    foreach(Runner::run($this->databaseId, $this->points) as $deliveryId)
      dispatch((new SendGeofenceWebhook($deliveryId))->onQueue('compass'));
  }

}
