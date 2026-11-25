<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Shares can now be named, stopped early, and left open-ended (expires_at NULL)
return new class extends Migration
{
    public function up()
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->string('label', 100)->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_viewed_at')->nullable();
            $table->index('token');
        });
    }

    public function down()
    {
        Schema::table('shares', function (Blueprint $table) {
            $table->dropIndex(['token']);
            $table->dropColumn(['label', 'revoked_at', 'last_viewed_at']);
        });
    }
};
