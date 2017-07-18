<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class Setup extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 100);
            $table->string('read_token', 255);
            $table->string('write_token', 255);
            $table->unsignedInteger('created_by');
            $table->datetime('created_at');
            $table->unique(['read_token','write_token']);
        });
        
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('url', 255);
            $table->datetime('created_at')->nullable();
            $table->datetime('last_login')->nullable();
        });

        Schema::create('database_users', function (Blueprint $table) {
            $table->unsignedInteger('database_id');
            $table->unsignedInteger('user_id');
            $table->datetime('created_at')->nullable();
            $table->primary(['database_id','user_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('users');
        Schema::drop('databases');
        Schema::drop('database_users');
    }
}
