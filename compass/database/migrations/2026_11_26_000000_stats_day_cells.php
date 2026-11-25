<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Where the track went each day, for the hexagons on the world map: one row
// per tenth-of-a-degree cell (about 11 km) touched on the ground. The map
// groups these into its hexagons when it is drawn, so the hexagon size can
// change without reprocessing the day files.
//
// Like the other stats tables this is rebuilt from the day files by
// "artisan stats:backfill".
return new class extends Migration
{
    public function up()
    {
        Schema::create('stats_day_cells', function (Blueprint $table) {
            $table->unsignedInteger('database_id');
            $table->date('utc_date');
            $table->date('local_date');
            // floor(latitude * 10) and floor(longitude * 10)
            $table->smallInteger('lat_i');
            $table->smallInteger('lng_i');
            // 0 when the cell was only passed through
            $table->unsignedInteger('stopped_s')->default(0);
            $table->primary(['database_id', 'utc_date', 'local_date', 'lat_i', 'lng_i']);
            $table->index(['database_id', 'local_date']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('stats_day_cells');
    }
};
