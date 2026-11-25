<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Data derived from the day files, for stats pages. Everything here can be
// emptied and rebuilt with "artisan stats:backfill"; the day files stay the
// source of truth.
//
// One UTC day file is the unit of work: reprocessing it replaces every row
// below that carries its date (shard_date / utc_date). Totals are keyed by the
// local date where the person was, so a local date usually adds up rows from
// two day files.
return new class extends Migration
{
    public function up()
    {
        // One row per database and day file: has it been processed, and with which version
        Schema::create('stats_shards', function (Blueprint $table) {
            $table->unsignedInteger('database_id');
            $table->date('shard_date');
            $table->unsignedSmallInteger('version')->nullable();
            $table->dateTime('dirty_at')->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('file_mtime')->nullable();
            $table->unsignedInteger('points_total')->nullable();
            $table->unsignedInteger('points_used')->nullable();
            $table->primary(['database_id', 'shard_date']);
            $table->index('dirty_at');
            $table->index(['database_id', 'version']);
        });

        Schema::create('stats_segments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('database_id');
            $table->date('shard_date');
            $table->unsignedSmallInteger('seq');
            $table->enum('type', ['stop', 'move']);
            // An inferred move is a jump across a hole in the data
            $table->boolean('inferred')->default(false);
            $table->string('mode', 10)->nullable();
            $table->enum('mode_source', ['auto', 'trip'])->default('auto');
            $table->string('motion', 10)->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->string('tz', 64);
            $table->date('local_date');
            $table->unsignedInteger('duration_s');
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('reported_distance_m')->nullable();
            $table->decimal('start_lat', 8, 5);
            $table->decimal('start_lng', 8, 5);
            $table->decimal('end_lat', 8, 5);
            $table->decimal('end_lng', 8, 5);
            $table->unsignedInteger('points')->default(0);
            $table->decimal('speed_p50', 6, 2)->nullable();
            $table->decimal('speed_p95', 6, 2)->nullable();
            $table->integer('alt_max')->nullable();
            $table->string('country', 3)->nullable();
            $table->unsignedInteger('city_id')->nullable();
            $table->string('countries', 60)->nullable();
            // Cut at UTC midnight; the other half is in the neighbouring day file's rows
            $table->boolean('clipped_start')->default(false);
            $table->boolean('clipped_end')->default(false);
            $table->unique(['database_id', 'shard_date', 'seq']);
            $table->index(['database_id', 'start_at']);
            $table->index(['database_id', 'end_at']);
        });

        Schema::create('stats_days', function (Blueprint $table) {
            $table->unsignedInteger('database_id');
            $table->date('utc_date');
            $table->date('local_date');
            // Ground distance; flights are kept apart
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('flight_distance_m')->default(0);
            $table->unsignedInteger('moving_s')->default(0);
            $table->unsignedInteger('stopped_s')->default(0);
            $table->primary(['database_id', 'utc_date', 'local_date']);
            $table->index(['database_id', 'local_date']);
        });

        Schema::create('stats_day_modes', function (Blueprint $table) {
            $table->unsignedInteger('database_id');
            $table->date('utc_date');
            $table->date('local_date');
            $table->string('mode', 10); // '' when the mode is unknown
            $table->unsignedInteger('distance_m')->default(0);
            $table->unsignedInteger('duration_s')->default(0);
            $table->unsignedSmallInteger('moves')->default(0);
            $table->primary(['database_id', 'utc_date', 'local_date', 'mode']);
            $table->index(['database_id', 'local_date']);
        });

        Schema::create('stats_day_places', function (Blueprint $table) {
            $table->unsignedInteger('database_id');
            $table->date('utc_date');
            $table->date('local_date');
            $table->string('country', 3)->default('');
            $table->unsignedInteger('city_id')->default(0); // 0 for time on the move, or stopped outside any city
            $table->unsignedInteger('stopped_s')->default(0);
            $table->unsignedInteger('moving_s')->default(0);
            $table->primary(['database_id', 'utc_date', 'local_date', 'country', 'city_id']);
            $table->index(['database_id', 'local_date']);
            $table->index(['database_id', 'country', 'local_date']);
            $table->index(['database_id', 'city_id', 'local_date']);
        });

        Schema::table('databases', function (Blueprint $table) {
            // When a job to process new data was last queued, so a busy device queues one job, not hundreds
            $table->dateTime('stats_job_at')->nullable();
        });
    }

    public function down()
    {
        Schema::table('databases', function (Blueprint $table) {
            $table->dropColumn('stats_job_at');
        });
        Schema::dropIfExists('stats_day_places');
        Schema::dropIfExists('stats_day_modes');
        Schema::dropIfExists('stats_days');
        Schema::dropIfExists('stats_segments');
        Schema::dropIfExists('stats_shards');
    }
};
