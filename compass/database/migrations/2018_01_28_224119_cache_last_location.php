<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CacheLastLocation extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->longtext('last_location')->nullable();
            $table->datetime('last_location_date')->nullable();
            $table->longtext('current_trip')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn('last_location');
            $table->dropColumn('last_location_date');
            $table->dropColumn('current_trip');
        });
    }
}
