<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// A fourth built-in area next to the city, region and country: the airport
// the device is at. The airports themselves are a file, resources/geo/airport-places.csv.
return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE geofences MODIFY place_level ENUM('city', 'region', 'country', 'airport') NULL");
    }

    public function down()
    {
        DB::table('geofences')->where('place_level', 'airport')->delete();
        DB::statement("ALTER TABLE geofences MODIFY place_level ENUM('city', 'region', 'country') NULL");
    }
};
