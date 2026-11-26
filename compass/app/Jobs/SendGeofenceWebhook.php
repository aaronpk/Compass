<?php
namespace App\Jobs;

use App\Geofence\Webhook;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\DB;

// Sends one web hook for one geofence event, and tries again later if the
// receiver doesn't answer with a 2xx status.
class SendGeofenceWebhook extends Job {

  // Seconds to wait before each further try
  const RETRY_AFTER = [10, 60, 300, 1800, 3600];

  // The retries are counted on the delivery; this only has to be more than them
  public $tries = 10;

  public function __construct(private int $deliveryId) {}

  public function handle() {
    $delivery = DB::table('geofence_deliveries')->where('id', $this->deliveryId)->first();
    if(!$delivery || $delivery->status != 'pending')
      return;
    $hook = DB::table('geofence_webhooks')->where('id', $delivery->webhook_id)->first();
    $event = DB::table('geofence_events')->where('id', $delivery->event_id)->first();
    $fence = $event ? DB::table('geofences')->where('id', $event->geofence_id)->first() : null;
    $database = $event ? DB::table('databases')->where('id', $event->database_id)->first() : null;
    // The web hook or its place was removed while this was waiting
    if(!$hook || $hook->deleted_at || !$fence || $fence->deleted_at || !$database) {
      DB::table('geofence_deliveries')->where('id', $delivery->id)->update(['status' => 'failed']);
      return;
    }

    $status = Webhook::send($hook->url, Webhook::payload($event, $fence, $database), Webhook::secret($database));
    $attempts = $delivery->attempts + 1;
    $update = ['attempts' => $attempts, 'response_code' => $status ?: null];

    if($status >= 200 && $status < 300) {
      $update += ['status' => 'sent', 'delivered_at' => date('Y-m-d H:i:s')];
    } elseif($attempts > count(self::RETRY_AFTER) || !$this->job || $this->job instanceof SyncJob) {
      // Out of tries, or there is no queue to try again from
      $update['status'] = 'failed';
    }
    DB::table('geofence_deliveries')->where('id', $delivery->id)->update($update);

    if(!isset($update['status']))
      $this->release(self::RETRY_AFTER[$attempts - 1]);
  }

}
