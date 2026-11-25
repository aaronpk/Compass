<?php
namespace App\Sharing;

use Illuminate\Support\Facades\Log;

// Called when someone opens a live share, to ask the tracking device for a
// fresh location.
//
// Nothing can be sent to the device yet. When Overland supports server push
// (https://github.com/aaronpk/Overland-iOS/issues/141), bind a subclass in
// the service container that sends it:
//
//   $this->app->bind(LocationRequester::class, OverlandPushRequester::class);
class LocationRequester {

  public function request(object $database): void {
    Log::info('A viewer opened a live share of '.$database->name);
  }

}
