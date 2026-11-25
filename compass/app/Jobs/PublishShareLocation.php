<?php
namespace App\Jobs;

use App\Sharing\Shares;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

// Pushes newly received locations to everyone watching a share of the
// database, through nginx's nchan module: one channel per share token.
class PublishShareLocation extends Job {

  public function __construct(private int $databaseId, private array $points) {}

  public function handle() {
    $publish = config('compass.nchan_publish_url');
    if(!$publish)
      return;
    $client = new Client(['timeout' => 5, 'http_errors' => false]);
    foreach(Shares::activeFor($this->databaseId) as $share) {
      try {
        $client->post(rtrim($publish, '/').'/'.$share->token, [
          'headers' => ['Content-Type' => 'application/json'],
          'body' => json_encode(['points' => $this->points], JSON_UNESCAPED_SLASHES),
        ]);
      } catch(\Exception $e) {
        // Viewers fall back to polling, so a failed push only costs a few seconds
        Log::warning('Could not publish to share '.$share->id.': '.$e->getMessage());
      }
    }
  }

}
