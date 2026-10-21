<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Laravel 12 records the exception when a queued job fails. The failed_jobs
// table from Lumen 5.1 has no column for it, so failing jobs couldn't be logged.
class FailedJobsException extends Migration
{
    public function up()
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->longText('exception')->nullable()->after('payload');
        });
    }

    public function down()
    {
        Schema::table('failed_jobs', function (Blueprint $table) {
            $table->dropColumn('exception');
        });
    }
}
