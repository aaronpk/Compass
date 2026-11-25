<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A token that only gives access to the embeddable world map of countries
// visited. Unlike the read token it can be published on a website.
return new class extends Migration
{
    public function up()
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->string('embed_token', 40)->nullable()->unique();
        });
    }

    public function down()
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn('embed_token');
        });
    }
};
