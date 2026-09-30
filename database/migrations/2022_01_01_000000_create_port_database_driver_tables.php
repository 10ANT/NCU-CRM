<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of the MySQL compatibility port. Not upstream code.
 *
 * The tenant-database counterpart of `database/landlord/2026_09_29_000000_create_port_database_driver_tables.php`.
 *
 * Upstream runs its queue on Redis or SQS and its sessions and cache on Redis, so it never needed
 * the tables Laravel's `database` drivers require and never shipped migrations for them.
 *
 * Tenant databases need them as well as the landlord, because in tenant context the default
 * connection is the tenant one, so anything queued while a tenant is active looks for `jobs` there.
 * The audit trail does exactly that: creating a role during a tenant migration dispatches
 * `OwenIt\Auditing\Listeners\ProcessDispatchAudit` onto the queue.
 *
 * Its filename timestamp is deliberately the earliest of any tenant migration, because the table has
 * to exist before any later migration causes a job to be queued.
 *
 * This port moves those drivers onto the database, which needs four tables:
 *
 * - `jobs`, for `QUEUE_CONNECTION=database`. Without it tenant provisioning fails immediately,
 *   because `tenants:create` dispatches `MigrateTenantDatabase` onto the queue.
 * `sessions` is deliberately NOT created here. Upstream creates it in the tenant database in
 * `database/migrations/2024_01_12_233513_create_sessions_table.php` and drops it again in
 * `2024_12_19_165310_drop_sessions_table.php`, so tenant databases are meant to have no sessions
 * table. Sessions live in the landlord database, which is why `.env` sets
 * `SESSION_CONNECTION=landlord`.
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
        Schema::dropIfExists('jobs');
    }
};
