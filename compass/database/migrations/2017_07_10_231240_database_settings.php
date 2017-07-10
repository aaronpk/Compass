<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class DatabaseSettings extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->string('timezone', 100)->default('UTC');
            $table->boolean('metric')->default(true);
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
            $table->dropColumn('timezone');
            $table->dropColumn('metric');
        });
    }
}
