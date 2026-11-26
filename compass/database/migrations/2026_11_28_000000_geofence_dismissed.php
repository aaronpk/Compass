<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Suggested places that someone has said they don't want. A suggestion is
// worked out afresh from the recorded stops each time the page is opened, so
// what is remembered is the spot: anything found within a short distance of
// it is left off the list.
return new class extends Migration
{
    public function up()
    {
        Schema::create('geofence_dismissed', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('database_id');
            $table->decimal('lat', 8, 5);
            $table->decimal('lng', 8, 5);
            $table->dateTime('created_at');
            $table->index('database_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('geofence_dismissed');
    }
};
