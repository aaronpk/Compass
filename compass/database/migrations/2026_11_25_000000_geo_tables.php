<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Lookup data for the offline country, city and timezone resolver.
// geo_cities and geo_countries are filled by "artisan geo:import"; the two
// cell tables are caches that fill themselves and can be emptied at any time.
return new class extends Migration
{
    public function up()
    {
        Schema::create('geo_cities', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // GeoNames id
            $table->string('name', 200);
            $table->string('country', 3);
            $table->string('admin1', 20)->nullable();
            $table->decimal('lat', 8, 5);
            $table->decimal('lng', 8, 5);
            $table->unsignedInteger('population');
            $table->string('tz', 64)->nullable();
            $table->index(['lat', 'lng']);
        });

        Schema::create('geo_countries', function (Blueprint $table) {
            $table->string('code', 3)->primary(); // ISO alpha-2, or Natural Earth's code where there is none
            $table->string('iso3', 3);
            $table->string('name', 100);
            $table->string('continent', 30);
            $table->string('sovereign', 3);
        });

        Schema::create('geo_cells', function (Blueprint $table) {
            $table->char('geohash', 7)->primary();
            $table->string('country', 3)->nullable();
            $table->unsignedInteger('city_id')->nullable();
        });

        Schema::create('geo_tz_cells', function (Blueprint $table) {
            $table->char('geohash', 4)->primary();
            $table->string('tz', 64)->nullable();
        });
    }

    public function down()
    {
        Schema::dropIfExists('geo_tz_cells');
        Schema::dropIfExists('geo_cells');
        Schema::dropIfExists('geo_countries');
        Schema::dropIfExists('geo_cities');
    }
};
