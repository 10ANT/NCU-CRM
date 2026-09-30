<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * Upstream runs its queue on Redis or SQS and its sessions and cache on Redis, so it never needed
 * the tables Laravel's `database` drivers require and never shipped migrations for them.
 *
 * This port moves those drivers onto the database, which needs four tables:
 *
 * - `jobs`, for `QUEUE_CONNECTION=database`. Without it tenant provisioning fails immediately,
 *   because `tenants:create` dispatches `MigrateTenantDatabase` onto the queue.
 * - `sessions`, for `SESSION_DRIVER=database`.
 * - `cache` and `cache_locks`, for `CACHE_DRIVER=database`. `cache_locks` is what gives Laravel
 *   atomic locks on the database store, which is the mechanism replacing `Redis::funnel()` in the
 *   calendar concurrency limiter without weakening its guarantees.
 *
 * This adds capability rather than replacing any, so it is a new file and no upstream migration is
 * touched. The tables are all standard Laravel schemas, except that `sessions.user_id` is a 36
 * character string rather than a big integer, because every user id in this application is a UUID.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('jobs')) {
            Schema::create('jobs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::hasTable('sessions')) {
            Schema::create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('user_id', 36)->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }

        if (! Schema::hasTable('cache')) {
            Schema::create('cache', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->mediumText('value');
                $table->integer('expiration');
            });
        }

        if (! Schema::hasTable('cache_locks')) {
            Schema::create('cache_locks', function (Blueprint $table) {
                $table->string('key')->primary();
                $table->string('owner');
                $table->integer('expiration');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('jobs');
    }
};
