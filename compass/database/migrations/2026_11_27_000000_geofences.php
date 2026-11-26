<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Geofences ("places"): areas the device can be in or out of, and watches on
// the city, region or country it is in. A place's current state is kept on
// its own row, so it survives restarts, and geofence_events is its history.
//
// A place is useful on its own: Compass knows whether the device is there.
// Web hooks are separate. A place can have any number of them, each for the
// events it chooses, and geofence_deliveries records how each one went for
// each event.
//
// geo_admin1 holds the names of regions (states, provinces) from GeoNames and
// is filled by "artisan geo:import".
return new class extends Migration
{
    public function up()
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('database_id');
            $table->string('name', 100);
            $table->enum('kind', ['circle', 'polygon', 'place']);

            // circle
            $table->decimal('lat', 8, 5)->nullable();
            $table->decimal('lng', 8, 5)->nullable();
            $table->unsignedInteger('radius')->nullable(); // metres
            // polygon: a GeoJSON Polygon or MultiPolygon geometry
            $table->longText('geojson')->nullable();
            // place: which level to watch for a change
            $table->enum('place_level', ['city', 'region', 'country'])->nullable();

            // Where the device is now. For a place watch, "value" is the
            // current city id, region code (US.OR) or country code.
            $table->enum('state', ['unknown', 'outside', 'inside'])->default('unknown');
            $table->dateTime('state_since')->nullable();
            $table->string('value', 40)->nullable();
            $table->string('value_label', 200)->nullable();
            // A change that has been seen but not yet confirmed (JSON)
            $table->string('candidate', 500)->nullable();

            $table->dateTime('created_at');
            $table->dateTime('deleted_at')->nullable();
            $table->index(['database_id', 'deleted_at']);
        });

        Schema::create('geofence_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('geofence_id');
            $table->unsignedInteger('database_id');
            $table->enum('type', ['enter', 'exit', 'cross', 'change']);
            // When it happened, from the point's own time
            $table->dateTime('event_at');
            $table->dateTime('created_at');
            $table->decimal('lat', 8, 5);
            $table->decimal('lng', 8, 5);
            $table->unsignedInteger('accuracy')->nullable();
            // For a place change
            $table->string('from_value', 40)->nullable();
            $table->string('from_label', 200)->nullable();
            $table->string('to_value', 40)->nullable();
            $table->string('to_label', 200)->nullable();
            // The point was already old when it reached the server
            $table->boolean('delayed')->default(false);

            // Looking at the same points twice must not record an event twice
            $table->unique(['geofence_id', 'type', 'event_at']);
            $table->index(['database_id', 'event_at']);
        });

        Schema::create('geofence_webhooks', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('geofence_id');
            $table->unsignedInteger('database_id');
            $table->string('url', 1024);
            // Which events it is sent for. A watch on the city, region or
            // country only has one kind of event, and ignores these.
            $table->boolean('on_enter')->default(false);
            $table->boolean('on_exit')->default(false);
            $table->boolean('on_cross')->default(false);
            // Don't send for an event older than this many seconds when it
            // is found. NULL sends it however old it is.
            $table->unsignedInteger('max_age')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('deleted_at')->nullable();
            $table->index(['geofence_id', 'deleted_at']);
        });

        // One web hook's turn at one event
        Schema::create('geofence_deliveries', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('event_id');
            $table->unsignedInteger('webhook_id');
            // skipped: the event was older than the web hook's limit
            $table->enum('status', ['pending', 'sent', 'failed', 'skipped'])->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->unique(['event_id', 'webhook_id']);
        });

        Schema::table('databases', function (Blueprint $table) {
            // How far the geofences have read, and the last point they
            // accepted (JSON)
            $table->string('geofence_cursor', 500)->nullable();
            // Signs the geofence web hooks
            $table->string('webhook_secret', 64)->nullable();
        });

        Schema::create('geo_admin1', function (Blueprint $table) {
            $table->string('code', 24)->primary(); // country.admin1, as GeoNames writes it: US.OR
            $table->string('name', 200);
        });
    }

    public function down()
    {
        Schema::dropIfExists('geo_admin1');
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn(['geofence_cursor', 'webhook_secret']);
        });
        Schema::dropIfExists('geofence_deliveries');
        Schema::dropIfExists('geofence_webhooks');
        Schema::dropIfExists('geofence_events');
        Schema::dropIfExists('geofences');
    }
};
