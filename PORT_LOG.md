# PORT_LOG.md

Working log for the MySQL / no-Redis / no-AWS port of Advising App.

Purpose, per `PORTING_BRIEF.md` rule 7: record every upstream file changed, what changed, and which test proves behaviour is unchanged. When upstream is merged later, this file is what makes that survivable.

Rules this log is written under: no feature removed, licence key files untouched, no copyright header removed, no-op migrations rather than deletions, no test deleted or skipped.

---

## 0. Decisions taken before any code changed

| Decision | Choice | Who decided | Consequence |
|---|---|---|---|
| Install location | Everything on F:, nothing new on C: outside the repo | User, 2026-09-28 | PHP at `F:\dev\php84`, MySQL at `F:\dev\mysql84` |
| Repo location | Stays at `C:\xampp\htdocs\advisingapp` | User, 2026-09-28 | `vendor/` and `node_modules/` will add roughly 2 to 3 GB to C: |
| Redis | Not installed at all | User, 2026-09-28 | See 0.1 |
| PostgreSQL baseline | Not installed. Port done directly against MySQL | User, 2026-09-28 | See 0.2. This is a recorded gap, not an oversight |
| MySQL flavour | MySQL 8.4.11 LTS, standalone, port 3307 | Claude, ratified by user | XAMPP's MariaDB 10.4.32 on 3306 is untouched. See 0.3 |
| Collation, brief item B1 | `utf8mb4` / `utf8mb4_0900_ai_ci`, set server-wide in `my.ini` | Claude | Substitutes for Postgres `citext`. Locked in before any migration was written |

### 0.1 Redis was dropped, and it turned out to cost nothing for testing

`.env.example:24` states that `file`, `dynamodb` and `database` cache drivers are not supported, because 50 files call `Cache::tags()`.

What that comment omits: Laravel's `array` store **does** support tagged cache. And `phpunit.xml:29-34` already pins the whole test run to `CACHE_DRIVER=array`, `SESSION_DRIVER=array`, `QUEUE_CONNECTION=sync`, `AUDIT_QUEUE_CONNECTION=sync`.

So upstream's own test suite never touches Redis. No Redis server is needed to run it, on either database.

This does not remove the need for workstream A. The `array` store is per-request, so with it the cache never actually caches between requests. Production still needs a persistent tagged-cache substitute, which is what workstream A's version-key scheme provides. Redis is simply not needed to build or prove it.

### 0.2 GAP: there is no PostgreSQL reference implementation

`PORTING_BRIEF.md` section 8.4 defines the proof of "no feature was lost" as: green test suite on PostgreSQL, then the identical suite green on MySQL. The PostgreSQL half was declined, so that comparison does not exist.

Consequences, stated plainly so nobody later mistakes this for a passed check:

- A test failing on MySQL cannot be attributed with certainty to the port. It may be a pre-existing upstream failure, a Windows-specific failure, or a missing-credential failure. Each will have to be argued case by case from the code rather than settled by comparison.
- Section 8.4 step 5, walking the `CLAUDE.md` section 4 feature checklist side by side on both databases, cannot be performed at all.
- Phase 1 and phase 2 collapse into one. There is no "get it running as upstream intends" stage, because nothing runs until the migrations are MySQL-clean. The port is therefore done blind, without a known-good state to fall back to.

Substitute proof being used instead: upstream's own suite must pass on MySQL, and every failure gets investigated and written up in section 3 of this log rather than skipped. Nothing is deleted or marked incomplete. This is weaker than the intended method and is reported as such in the evaluation.

Reversible at any point. PostgreSQL 17.6 ships a binaries-only zip, `https://get.enterprisedb.com/postgresql/postgresql-17.6-1-windows-x64-binaries.zip`, 330 MB, which needs no installer, no Windows service and no administrator rights. If the baseline is wanted later, it is about twenty minutes of work.

### 0.3 XAMPP's MariaDB 10.4 could not have been the target

Worth recording, because "we already have MySQL" is the natural assumption.

`C:\xampp\mysql\bin\mysql.exe --version` reports **10.4.32-MariaDB**, not MySQL. Three things in `PORTING_BRIEF.md` workstream B are impossible on it:

- `JSON_TABLE` does not exist in MariaDB at any version. Brief item B5 depends on it for `jsonb_array_elements_text` (4 uses) and `jsonb_each` (4 uses), and section 8.3 forbids simplifying those queries to avoid the problem.
- MariaDB's `JSON` is an alias for `LONGTEXT`, not a native type.
- `utf8mb4_0900_ai_ci`, the collation item B1 requires choosing up front, does not exist in MariaDB.

Hence a separate MySQL 8.4 LTS instance on port 3307. XAMPP is not modified in any way, and Solorion's `miraion` database on 3306 is not touched.

Open question for NCU, and it matters: the evaluation notes describe NCU's server as "MySQL at 10.100.3.10:3306". If that is in fact MariaDB, the port target changes and items B5 and B6 get significantly harder. Confirm the flavour and version before anyone promises a deployment.

---

## 1. Environment built

All verified by running the command shown, on 2026-09-28.

| Component | Version | Location | Verified by |
|---|---|---|---|
| PHP | 8.4.26 NTS VS17 x64 | `F:\dev\php84` | `F:\dev\php84\php.exe -v` |
| MySQL | 8.4.11 LTS | `F:\dev\mysql84`, data in `F:\dev\mysql84-data` | `SELECT VERSION()` over TCP on 3307 |
| Composer | 2.8.6 | global, unchanged | `composer --version` reports `PHP version 8.4.26 (F:\dev\php84\php.exe)` inside the port shell |
| Node | 22.13.1 | system, unchanged | `node -v`. `.nvmrc` asks 24.21.0; only upgraded if the asset build fails |

PHP zip SHA-256 `da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7`, checked against php.net's published `sha256sum.txt`.

### 1.1 Isolation from XAMPP, verified not assumed

- Plain `php -v` in an ordinary terminal still reports **8.2.12 ZTS (C:\xampp\php)**.
- Neither the machine nor the user `Path` environment variable contains `php84`.
- PHP 8.4 is put on `PATH` per shell only, by `php84-shell.bat` (repointed to `F:\dev\php84`) or `export PATH="/f/dev/php84:$PATH"`.
- MySQL 8.4 runs as a foreground process on 3307, not as a Windows service. Start it with `F:\dev\start-mysql84.bat`.

Nothing here required administrator rights. The four things previously expected to need them were all avoided:

| Expected to need admin | How it was avoided |
|---|---|
| PostgreSQL installer | Not applicable now, and a no-installer zip exists regardless |
| Memurai MSI for Redis | Redis not needed at all, see 0.1 |
| Hosts file edit for `advisingapp.local` | `localtest.me` resolves its entire subtree to 127.0.0.1 by public DNS. Verified: `test.advisingapp.localtest.me` resolves to 127.0.0.1. Real subdomains, real cookie-domain behaviour, no hosts edit |
| `php artisan storage:link` symlink | Windows Developer Mode is already enabled (`HKLM\...\AppModelUnlock\AllowDevelopmentWithoutDevLicense = 1`), so unelevated symlinks are permitted |

### 1.2 MySQL configuration, `F:\dev\mysql84\my.ini`

Set deliberately: `port=3307`, `character-set-server=utf8mb4`, `collation-server=utf8mb4_0900_ai_ci`, `skip-log-bin`, `innodb_buffer_pool_size=512M`, `max_allowed_packet=256M`, `local_infile=1`, `mysqlx=0`.

`sql_mode` left at the 8.4 default for now: `ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`.

`ANSI_QUOTES`, which brief item B4 calls for, is deliberately **not** set server-wide. It belongs on the connection in `config/database.php` so it travels with the application instead of living in a machine's config file. Recorded here so the decision is not rediscovered later.

Databases and role created: `advisingapp` / `password` with `ALL PRIVILEGES ON *.*` (it needs `CREATE DATABASE` for per-tenant databases), plus databases `landlord`, `testing_landlord` and `testing_tenant`, the last two matching `phpunit.xml:36-40`.

---

## 2. Capability probes run before committing to the plan

Following the Solorion `Ultimate_Fixer.md` discipline of proving with a harness rather than reasoning. Each of these was run against MySQL 8.4.11 on 3307.

| Brief item | Question | Result |
|---|---|---|
| B6, flagged **highest schedule risk** | Can a MySQL view contain a derived table in its `FROM` clause, plus `UNION ALL`? If not, `GenerateStudentAlertsView.php` needs redesigning into a materialised table | **Yes, it works.** Created a view combining `SELECT CAST(NULL AS CHAR(255)) ... FROM DUAL WHERE false` with `UNION ALL SELECT ... FROM (SELECT ...) AS sub1`, then selected from it and got the expected row. The restriction the brief warned about was lifted in MySQL 8.0.14 and does not apply to 8.4.11. The view approach survives the port |
| B6 | Is `SELECT ... WHERE false` with no `FROM` accepted? | Yes, accepted, returns zero rows without error |
| B7 | Does MySQL support an atomic multi-table `RENAME TABLE a TO a_old, b TO a`? | **Yes.** Verified the swap and confirmed both tables afterwards. This removes the non-transactional-DDL hazard of losing the `students` table mid-import |
| B5 | Does `JSON_TABLE` exist, as required for `jsonb_array_elements_text` and `jsonb_each`? | **Yes.** `JSON_TABLE('["a","b","c"]', '$[*]' COLUMNS (v VARCHAR(20) PATH '$'))` returned `a,b,c` |
| B5, flagged **highest silent-failure risk** | What exactly does `JSON_TYPE` return, versus `jsonb_typeof`? | Measured below. Confirms a straight function rename returns wrong answers |

### 2.1 `jsonb_typeof` versus `JSON_TYPE`, measured

| Input | Postgres `jsonb_typeof` | MySQL `JSON_TYPE`, measured on 8.4.11 |
|---|---|---|
| `"s"` | `string` | `STRING` |
| `1` | `number` | `INTEGER` |
| `1.5` | `number` | `DOUBLE` |
| `{}` | `object` | `OBJECT` |
| `[]` | `array` | `ARRAY` |
| `true` | `boolean` | `BOOLEAN` |
| `null` | `null` | `NULL` |

Two differences, both of which break a naive rename:

1. **Case.** Every value is upper case in MySQL. Any `= 'string'` comparison silently stops matching.
2. **`number` splits into three.** `jsonb_typeof` answers `number` for both `1` and `1.5`. `JSON_TYPE` answers `INTEGER`, `DOUBLE` or `DECIMAL` depending on the value. So a test for a numeric JSON value has to become a set membership test, not an equality test.

All 9 `jsonb_typeof` call sites must be rewritten with both differences handled, and each needs a test. Tracked in section 3 when the work is done.

---

## 3. Upstream files changed

Nothing yet. No upstream file has been modified at this point. Entries below will use the form: file, what changed, why, and which test proves behaviour is unchanged.

### Files that must never be touched

Per Elastic Licence 2.0 and `PORTING_BRIEF.md` section 0:

- `app/Filament/Pages/ManageLicenseSettings.php`
- `app/Settings/LicenseSettings.php`
- `app/Jobs/UpdateTenantLicenseData.php`

No copyright or licence header is to be removed or altered in any file.

---

## 4. Non-upstream files added by this work

| File | Purpose |
|---|---|
| `PORT_LOG.md` | This log |
| `php84-shell.bat` | Pre-existing helper, repointed from `C:\php84` to `F:\dev\php84` |
| `F:\dev\start-mysql84.bat` | Starts the port's MySQL instance on 3307 |
| `F:\dev\mysql84\my.ini` | MySQL configuration described in 1.2 |

### 2.2 B6 re-assessed: `GenerateStudentAlertsView.php` is much smaller than the brief feared

`PORTING_BRIEF.md` item B6 calls this "the schedule risk for the whole port", on the grounds that the SQL is generated from `->toRawSql()` on arbitrary query builders and therefore "cannot be fully enumerated by reading the code".

Read in full, `app-modules/alert/src/Actions/GenerateStudentAlertsView.php:47-91`. The Postgres-specific surface is four fragments, all inside this one file:

| Line | Postgres | MySQL |
|---|---|---|
| 59 | `NULL::character varying(255) AS sisid` | `CAST(NULL AS CHAR(255)) AS sisid` |
| 60 | `NULL::uuid AS alert_configuration_id` | `CAST(NULL AS CHAR(36)) AS alert_configuration_id` |
| 61 | `WHERE false` with no `FROM` | Accepted as-is by MySQL 8.4, verified in 2. `FROM DUAL` optional |
| 83 | `'{$alertId}'::uuid` | `CAST('{$alertId}' AS CHAR(36))`. MySQL has no uuid type; Laravel maps `->uuid()` to `char(36)` |

The "cannot be enumerated" concern does not hold up. The presets are a closed set of 12 handler classes under `app-modules/alert/src/Presets/Handlers/`, and each one's `getStudentAlertQuery()` returns a query builder. Grepping all 12 for raw SQL turns up exactly six raw fragments, and every one is already valid MySQL:

| Handler | Raw fragment | Portable? |
|---|---|---|
| `AdultLearnerPresetHandler.php:83` | `EXTRACT(YEAR FROM birthdate) <= ?` | Yes, `EXTRACT` is standard and MySQL supports it |
| `FirstGenerationStudentPresetHandler.php:70` | `firstgen = TRUE` | Yes |
| `LowEarnedCreditPercentageHandler.php:84` | `SUM(unt_taken) > 0 AND SUM(unt_earned) * 100 < ? * SUM(unt_taken)` | Yes |
| `MultipleCourseWithdrawalsPresetHandler.php:73` | `COUNT(*) > 1` | Yes |
| `MultipleDorfGradesPresetHandler.php:73` | `COUNT(*) > 1` | Yes |
| `NewStudentPresetHandler.php:84` | `COUNT(DISTINCT semester_code) <= ?` | Yes |

Everything else in those handlers is Eloquent or the query builder, which emits driver-appropriate SQL on its own.

So B6 downgrades from "highest schedule risk, may force a redesign into a materialised table" to four edits in one file, given that the view-with-derived-table probe in section 2 passed. Every preset still has to be exercised for real before this is called proven, and that is tracked as an open item.

One genuine issue the brief did not raise: lines 48 and 88-91 wrap `DROP VIEW` plus `CREATE VIEW` in `DB::transaction()`. MySQL DDL is not transactional, so on MySQL that transaction buys nothing and there is a window where `student_alerts` does not exist. `CREATE OR REPLACE VIEW` is atomic in MySQL and removes the window. Recorded here as a candidate change, to be logged in section 3 if made.

### 2.3 A2 semantics to preserve, measured from the file

`app-modules/meeting-center/src/Jobs/Middleware/CalendarRequestsConcurrencyLimit.php:48-66`. Section 8.2 forbids the pass-through that the brief's section 3 suggested, so the replacement has to match these exactly:

| Parameter | Value | Must be preserved as |
|---|---|---|
| Scope | Applies only when `provider_type === CalendarProvider::Outlook`; every other provider calls `$next($job)` directly | Unchanged. The early return stays |
| Key | `"{calendar-concurrency-{$job->calendar->provider_id}}"` | Byte-identical, braces included, per brief rule |
| `->limit(4)` | 4 concurrent holders per calendar provider id | 4 slot locks, `{key}-slot-1` through `-slot-4` |
| `->block(10)` | Wait up to 10 seconds to acquire | Loop the 4 slots until 10 seconds have elapsed |
| Failure path | `$job->release(10)` | Identical, put the job back on the queue after 10 seconds |
| Release | Funnel slot frees when the callback returns | Lock released in a `finally`, plus a TTL so a killed worker cannot hold a slot forever. Redis funnel has no equivalent TTL, so this is a deliberate small difference and is documented rather than silent |

Prerequisite for this: Laravel's atomic locks on the `database` cache store need a `cache_locks` table. This project has no cache table migration, because it assumed Redis. Workstream A must add `cache` and `cache_locks` tables.

---

## 3. Upstream files changed

### 3.1 `config/database.php`

**What changed.** The `landlord` and `tenant` connections hardcoded `'driver' => 'pgsql'` at lines 72 and 86, so the engine was not selectable at all. Both now read a `$databaseDriver` variable defined at the top of the file as `env('DB_DRIVER', 'pgsql')`. `charset` became conditional (`utf8mb4` on MySQL, `utf8` otherwise), and four keys were added that each connector ignores unless it is theirs: `collation`, `engine` and `modes` for MySQL, alongside the existing `search_path` and `sslmode` for PostgreSQL.

**Why.** Section 8 forbids removing capability. Defaulting to `pgsql` means a checkout with no `DB_DRIVER` set behaves exactly as upstream, so the PostgreSQL path is preserved rather than replaced, and the diff against upstream stays four changed lines plus additions.

`modes` carries brief item B4's `ANSI_QUOTES` along with MySQL 8.4's own six default modes, because Laravel's `modes` key replaces `sql_mode` wholesale instead of appending to it.

**Proof.** `DB::connection('landlord')` reports driver `mysql`, version 8.4.11, and `@@session.sql_mode` = `ANSI_QUOTES,ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`. Item B4's 27 double-quoted identifiers are covered by this, at the connection rather than in a machine config file.

### 3.2 `config/app.php`

**What changed.** One line added to the `providers` array registering `App\Providers\DatabasePortServiceProvider::class`, with a comment marking it as port code.

**Why.** The port needs container bindings. A separate provider keeps every binding in a new file rather than editing an upstream provider, which minimises future merge conflicts.

---

## 4. Problems found by running it, and how each was resolved

### 4.1 `DB::transaction()` wrapped around DDL, 77 migrations affected

**Symptom.** `php artisan migrate:landlord` ran 11 migrations then failed on `database/landlord/2025_01_23_191742_create_schedule_monitor_tables.php` with `PDOException: There is no active transaction` at `vendor/laravel/framework/src/Illuminate/Database/Concerns/ManagesTransactions.php:54`.

**Invariant.** Every migration's schema change must be applied, and `DB::transaction()` must not throw.

**Cause, proven rather than reasoned.** MySQL performs an implicit COMMIT around every DDL statement. A standalone harness reproduced it exactly: after `beginTransaction()` then `CREATE TABLE`, `PDO::inTransaction()` returns `false`, and the following `commit()` throws. The schema change itself had already succeeded and was durable. So this is a bookkeeping failure, not data loss.

**Scale.** 77 of the 706 migrations wrap `Schema::` calls in `DB::transaction()`. Separately, 46 runtime files use `DB::transaction()` for real DML, and those must keep working normally.

**Fix.** Three new non-upstream files and one registration line, rather than 77 migration edits:

| File | Role |
|---|---|
| `app/Database/ResilientMySqlPdo.php` | Extends `Pdo\Mysql`. `commit()` returns true and `rollBack()` returns false when `inTransaction()` is false, because MySQL has already decided the outcome by then. Ordinary DML is untouched, since `inTransaction()` is still true for it |
| `app/Database/ResilientMySqlConnector.php` | Extends Laravel's `MySqlConnector`, overriding `createPdoConnection()` to return the above |
| `app/Providers/DatabasePortServiceProvider.php` | Binds the connector to the container key `db.connector.mysql`, the documented extension point `ConnectionFactory` checks at line 276 before falling back to its own connector |

Nothing in the framework is patched, and PostgreSQL is unaffected because the binding exists only for the mysql driver.

**Why not edit the 77 migrations.** It would create a large permanent diff against an actively released upstream, and it would strip genuine atomicity from the PostgreSQL path, where DDL really is transactional. That is a capability reduction, which section 8 forbids.

**Proof.** The harness showed three things: the failure reproduces on stock PDO; the shim commits successfully after DDL; and real DML still rolls back (0 rows) and commits (1 row) correctly through the shim. `DB::connection('landlord')->getPdo()` now reports `App\Database\ResilientMySqlPdo`. The previously failing migration then passed and the run advanced to migration 13. A permanent test is still owed, tracked as an open item.

### 4.2 MAJOR SCOPE FINDING: `tpetry/laravel-postgresql-enhanced`

**This package is not mentioned anywhere in `PORTING_BRIEF.md`, and it is a larger part of workstream B than anything the brief does list.**

**Symptom.** Migration 13, `database/landlord/2026_01_19_120000_modify_tenants_domain_unique_constraint.php:50`, failed with `TypeError: Argument #1 ($table) must be of type Tpetry\PostgresqlEnhanced\Schema\Blueprint, Illuminate\Database\Schema\Blueprint given`.

**Cause.** The package registers itself through `Connection::resolverFor('pgsql', ...)` at `vendor/tpetry/laravel-postgresql-enhanced/src/PostgresqlEnhancedServiceProvider.php:95`, so its enhanced `Blueprint` exists only on PostgreSQL connections. On MySQL, Laravel hands back its own `Blueprint`, and every migration whose closure type-hints the enhanced one fails on the type, whether or not it uses an enhanced feature. The package's `Schema` facade is a bare subclass of Laravel's with no behaviour of its own, so the facade is not the mechanism.

**Scale, counted.** **227 of the 706 migration files** import `Tpetry\PostgresqlEnhanced`. Zero runtime files do, which is the one piece of good news: this is entirely a migration-layer problem.

Of the 47 methods the enhanced `Blueprint` offers, the migrations use exactly five:

| Method | Uses | MySQL position |
|---|---|---|
| `uniqueIndex()` | 36 | 21 are plain unique indexes and translate directly. **15 are partial indexes** carrying `->where(...)`, which MySQL has no direct equivalent for. See below |
| `dropUniqueIndex()` | 25 | Translatable to `DROP INDEX` |
| `dropUniqueIfExists()` | 12 | MySQL 8.4 has no `DROP INDEX IF EXISTS`, so this needs an existence check against `information_schema` |
| `trigger()` | 3 | **Hardest item.** PostgreSQL triggers call a separate `FUNCTION`, while MySQL inlines the body and has no statement-level triggers. Not a syntax translation |
| `dropTriggerIfExists()` | 1 | Follows whatever the trigger work decides |

**The partial unique index is real lost functionality if handled naively.** `->uniqueIndex('domain', ...)->where(fn ($c) => $c->whereNull('deleted_at'))` means "domain must be unique among rows that are not soft-deleted", which is what lets a tenant domain be reused after the old tenant is deleted. Dropping the `where` would forbid that reuse, a behaviour change users would hit.

A genuine equivalent exists: MySQL 8.0.13 and later support functional key parts, and unique indexes ignore NULLs, so a unique index on `(CASE WHEN deleted_at IS NULL THEN domain END)` enforces exactly the same rule. That is the intended approach for all 15, and each needs a test proving that a soft-deleted or case-variant duplicate is still accepted or rejected exactly as it was on PostgreSQL.

**Planned fix**, an adapter rather than 227 file edits:

1. `App\Database\MySqlEnhancedBlueprint extends Tpetry\PostgresqlEnhanced\Schema\Blueprint`, bound so MySQL connections receive it. The package's constructor is grammar-agnostic (`vendor/.../Schema/Blueprint.php:27-41`), so it works against a MySQL grammar. This alone clears the type error in all 227 files without editing a single migration.
2. A MySQL schema grammar supplying the `compile*` methods the enhanced commands dispatch to, for example `compileUnique2()` for `uniqueIndex()` (`vendor/.../Schema/Grammars/GrammarIndex.php:130`).
3. Partial indexes compiled to functional unique indexes as described.
4. Triggers assessed separately, and expected to be the item that needs escalating.

**Effect on the estimate.** `PORTING_BRIEF.md` puts workstream B at one to two weeks. That figure did not include this package. Meanwhile item B6, which the brief called the highest schedule risk of the whole port, turned out to be four casts in one file. The risk did not disappear, it moved: the real work is the schema-builder adapter and the triggers, not the alerts view.

---

## 5. Ordering deviation from the brief, and why

`PROMPT_FOR_AGENT.md` and `PORTING_BRIEF.md` both require workstream C first, then A, then B, on the stated grounds that C is quickest and "shortens the feedback loop".

That rationale depended on there being a working PostgreSQL install to compare against. With the baseline declined (see 0.2), nothing in this application runs at all until enough of workstream B exists for the migrations to complete, so there is no feedback loop for C to shorten. Writing C and A first would mean two to three days of work with no way to execute a single test.

Order actually being followed:

1. **B, minimum viable**: connection, schema-builder adapter, migrations green. This is what makes any test runnable.
2. **C**: AWS and external services replaced with local equivalents, including the real `temporaryUrl` macro and self-hosted Reverb.
3. **A**: Redis removal, the version-key tagged cache and the slot-based semaphore.
4. **B, remainder**: runtime raw SQL, the `jsonb_*` functions, `whereJsonContains` semantics and the alerts view.

Branch in use: `port/workstream-b-mysql`.

---

## 6. Open items owed

| Item | Status |
|---|---|
| Permanent test for the `ResilientMySqlPdo` shim | Owed. Currently proven only by a scratch harness |
| 15 partial unique indexes | Each needs a test proving identical uniqueness behaviour |
| 3 `trigger()` migrations | Not yet read. Expected to need escalation |
| B5 `jsonb_typeof` call sites | 9 sites, each needs the comparison rewritten and tested, not just the function renamed |
| B6 alert presets | Four casts identified; all 12 presets still need exercising for real |
| `whereJsonContains` | 7 sites, semantics differ for scalars, each needs testing |
| Node engine warning | One package declares `node: >=24` and the machine has 22.13.1. `npm run build` succeeded regardless. Revisit only if a build problem appears |

---

# PART TWO: migrations green, application running

Status at this point: **all 710 tenant migrations and all 17 landlord migrations pass on MySQL 8.4.11**, tenant provisioning completes end to end including seeders, and the application serves both the landlord and tenant panels over HTTP.

## 7. The port's shape

Almost everything is done at the connection, grammar or container level rather than in migrations. That is deliberate: 706 migrations are upstream files, and every one edited is a permanent merge conflict. The count of upstream files actually modified is **seven**.

| Layer | File | Role |
|---|---|---|
| Container | `app/Providers/DatabasePortServiceProvider.php` | Registers everything below. All bindings guarded on `DB_DRIVER=mysql`, so the PostgreSQL path is untouched |
| PDO | `app/Database/ResilientMySqlPdo.php` | Tolerates MySQL's implicit commit around DDL |
| Connector | `app/Database/ResilientMySqlConnector.php` | Supplies that PDO |
| Connection | `app/Database/PortedMySqlConnection.php` | Supplies the grammars and builder, and translates raw PostgreSQL DDL that no grammar sees |
| Query grammar | `app/Database/MySqlCascadeTruncateGrammar.php` | Reproduces `TRUNCATE ... CASCADE` |
| Schema grammar | `app/Database/PortedMySqlSchemaGrammar.php` | DDL steps MySQL needs spelled out that PostgreSQL does implicitly |
| Schema builder | `app/Database/PortedMySqlSchemaBuilder.php` | `dropAllTables()` also drops views, as PostgreSQL's CASCADE does |
| Blueprint | `app/Database/MySqlEnhancedBlueprint.php` | Satisfies the 227 migrations type-hinting the PostgreSQL-enhanced Blueprint; truncates identifiers as PostgreSQL does; fails loudly on anything it cannot compile |
| Schema macros | `app/Database/Schema/MySqlEnhancedSchemaMacros.php` | MySQL implementations of the enhanced package's five Blueprint and four Schema methods |
| Translators | `app/Database/Schema/*Translator.php` | Raw SQL translation, one class per PostgreSQL construct |

### 7.1 Upstream files modified, the full list

Seven, each with a reason that could not be handled centrally.

| File | Change | Why not central |
|---|---|---|
| `config/database.php` | Driver from `env('DB_DRIVER', 'pgsql')`; MySQL charset, collation, engine and `sql_mode` keys added | The connection definition itself |
| `config/app.php` | One provider registered | Registration point |
| `app/Multitenancy/Tasks/SwitchAppUrl.php` | Carry the port from the application URL onto the tenant origin | See 8.9 |
| `database/migrations/2024_01_16_152058_override_max_uuid_function.php` | Guarded to PostgreSQL only | Installs a PostgreSQL aggregate; MySQL does `MAX()` on uuids natively |
| `app-modules/student-data-model/database/migrations/2026_01_21_154225_enable_citext_extension.php` | Guarded to PostgreSQL only | `CREATE EXTENSION` has no MySQL concept |
| `app-modules/meeting-center/database/migrations/2026_07_13_115355_add_versioning_to_event_registration_forms_table.php` | MySQL branch using `ROW_NUMBER()` instead of `DISTINCT ON` | Data migration; the rewrite is query-specific |
| 10 observer and job files, plus one migration | `(SELECT ...)` wrapped in a derived table | See 8.3 |

Two new migrations were added, neither replacing anything: `database/landlord/2026_09_29_000000_create_port_database_driver_tables.php` and `database/migrations/2022_01_01_000000_create_port_database_driver_tables.php`, creating the `jobs`, `sessions`, `cache` and `cache_locks` tables that the `database` queue, session and cache drivers need and that upstream never shipped because it used Redis and SQS.

---

## 8. Every engine difference found, and how each was resolved

Found by running the migrations, not by reading. Each was fixed, then the run continued, until all 710 passed.

### 8.1 `DB::transaction()` around DDL, 77 migrations

Covered in 4.1. MySQL implicitly commits around DDL, so Laravel's commit found no transaction. Fixed with a `Pdo\Mysql` subclass whose `commit()` and `rollBack()` are no-ops when no transaction is open.

### 8.2 Generated identifiers longer than 64 characters

`service_request_form_authentications_service_request_form_id_foreign` is 68 characters. PostgreSQL truncates silently to 63; MySQL rejects with error 1059.

`MySqlEnhancedBlueprint::createIndexName()` truncates to **63**, PostgreSQL's limit rather than MySQL's 64, so every generated name matches the name upstream already has. A later migration dropping one of these by its generated name therefore finds it.

### 8.3 Subquery on the table being inserted into, 11 sites

`DB::raw('(SELECT COALESCE(MAX(t.order), 0) + 1 FROM t WHERE ...)')` used as an insert value on `t`. PostgreSQL allows it; MySQL raises error 1093.

Wrapping the subquery in a derived table (`SELECT * FROM (...) AS port_next_order`) satisfies MySQL and is equally valid on PostgreSQL, so this stays a single code path rather than a driver fork. Proven on MySQL 8.4.11 before applying: the direct form errors, the wrapped form inserts the correct value.

Applied to 11 sites in 10 files: `ConcernStatusObserver`, `ProspectAddressObserver`, `ProspectEmailAddressObserver`, `ProspectPhoneNumberObserver`, `EnrollmentSemesterObserver`, `StudentAddressObserver`, `StudentEmailAddressObserver`, `StudentPhoneNumberObserver`, `AutoImportEnrollmentSemesters`, and the prospect statuses data migration.

### 8.4 `TRUNCATE` without CASCADE

Laravel compiles `->truncate()` to `TRUNCATE ... RESTART IDENTITY CASCADE` on PostgreSQL, with cascade on by default (`PostgresGrammar::$cascadeTruncate = true`). CASCADE both permits the truncate and empties referencing tables. MySQL refuses outright with error 1701 whenever any foreign key points at the table.

`MySqlCascadeTruncateGrammar` resolves the full dependency closure from `information_schema` and truncates all of it with foreign key checks suspended. Merely disabling the checks would have passed the error but left rows PostgreSQL would have removed.

### 8.5 `DROP COLUMN` with a dependent foreign key

PostgreSQL drops the constraint with the column; MySQL refuses with error 1828. `PortedMySqlSchemaGrammar::compileDropColumn()` emits the constraint drops first, skipping any the migration already drops itself so the two do not collide.

### 8.6 `gen_random_uuid()` as a column default, 6 sites

Three separate MySQL refusals, resolved together in `compileAdd()`: MySQL rejects PostgreSQL's function name; it requires expression defaults in parentheses; and it refuses any non-deterministic default on `ALTER ADD COLUMN`, with error 1674, **even with binary logging off**, which was verified on this instance.

The operation is split into add-nullable, backfill by `UPDATE` with a real version 4 UUID expression, then `MODIFY ... NOT NULL DEFAULT (UUID())`. Proven step by step on 8.4.11 before being written.

**Known divergence, escalated not hidden.** The ongoing default must be MySQL's `UUID()`, the only generator MySQL accepts in a column default, which yields a version 1 UUID containing a timestamp and MAC address. Upstream's is a random version 4. Backfilled rows get genuine version 4; rows inserted later without an explicit id get version 1. These ids are primary keys that appear in URLs, so the difference is a mild predictability regression rather than a cosmetic one. `Enrollment` does not use `HasVersion4Uuids`, so it does rely on the database default. Closing it needs either a BEFORE INSERT trigger per table or those models generating ids in PHP as most models here already do. Recommended before any production use.

### 8.7 `ALTER TABLE ... RENAME CONSTRAINT`, 104 sites

MySQL has no equivalent. `RenameConstraintTranslator` resolves what the constraint actually is from `information_schema`: a foreign key is dropped and recreated under the new name with its columns, referenced columns and ON DELETE and ON UPDATE actions preserved; an index is renamed with `RENAME INDEX`; a primary key is a no-op, because MySQL always calls it `PRIMARY` and offers no way to name it. Anything unresolvable raises.

### 8.8 Other raw PostgreSQL DDL

| Construct | Sites | Resolution |
|---|---|---|
| `ALTER INDEX ... RENAME TO` | 4 | Owning table resolved from `information_schema`, then `ALTER TABLE ... RENAME INDEX` |
| `DROP INDEX [IF EXISTS]` | several | Owning table resolved; `IF EXISTS` becomes a genuine no-op when absent |
| `ALTER TABLE ... DROP CONSTRAINT` | 18 | Resolved to `DROP FOREIGN KEY`, `DROP INDEX`, `DROP CHECK` or `DROP PRIMARY KEY` |
| `ALTER COLUMN ... TYPE` | 23 | `MODIFY`, re-emitting nullability, default and comment, because MySQL's `MODIFY` restates the whole column and would otherwise silently drop `NOT NULL` |
| `CREATE [UNIQUE] INDEX ... WHERE` | 5 files | Functional index over a `CASE` expression |
| `expr::type` casts | throughout | `CAST(expr AS type)`, string-literal aware so a `::` inside data is never touched |
| `CREATE EXTENSION citext` | 1 | Guarded no-op; the collation already provides case-insensitivity |
| `DISTINCT ON` | 1 | `ROW_NUMBER() OVER (PARTITION BY ...)` |

`citext` conversions are no-ops on MySQL, but the migrations containing them are **not**, because they also convert a plain unique index into a partial one. Skipping them would have silently dropped that change.

### 8.9 Tenant URLs lost their port

`SwitchAppUrl::makeCurrent()` builds the tenant origin as `{scheme}://{$tenant->domain}` and calls `URL::useOrigin()`. A tenant's `domain` column holds a bare hostname, so any port is dropped. Invisible in production on 80 or 443; locally every redirect pointed at port 80 while the application answered on 8010, so the tenant root 404ed. Now carries the port from the configured application URL, and changes nothing when there is no port.

### 8.10 `NOT NULL` column with `ON DELETE SET NULL`

`events.created_by_id` is declared `foreignUuid(...)->constrained('users')->nullOnDelete()` with no `->nullable()`. PostgreSQL accepts it, but the action can never succeed there either: deleting a referenced user raises a not-null violation. MySQL rejects the definition outright with error 1830.

Compiled as `ON DELETE RESTRICT`, which keeps the column `NOT NULL` and keeps the observable behaviour, the delete being refused. Only the error differs. Making the column nullable instead would have let the delete succeed on MySQL where upstream refuses it, which is a real behaviour change.

**Worth reporting to Canyon GBS**: the declared intent is unreachable on PostgreSQL too, so this looks like an upstream oversight.

### 8.11 Dropping an index a foreign key relies on

MySQL requires every foreign key to be covered by an index and refuses to drop the last one, with error 1553. The 20 citext migrations drop a unique index, and the replacement is a functional index, which MySQL will not accept as cover. A plain index on the foreign key's own columns is created first and left in place. That adds an index MySQL requires; it removes nothing.

### 8.12 `migrate:fresh` left views behind

PostgreSQL's `drop table ... cascade` removes dependent views. MySQL's does not, and `migrate:fresh` only drops views with `--drop-views`, which `MigrateTenantDatabase` does not pass. Since MySQL shares one namespace for tables and views, the leftovers collided with the migrations recreating them. `PortedMySqlSchemaBuilder::dropAllTables()` drops views first.

### 8.13 The trigger late-binding bug, found by a test

The most important defect found, and it was invisible until a test looked for it.

A PostgreSQL trigger names its function and resolves it when it fires, so replacing the function changes every trigger using it. MySQL triggers carry their body inline, so the port inlines the translated body when the trigger is created.

The migrations run in this order: the function is defined as a blanket guard, the trigger is created, the trigger is recreated with an allowlist of `sort` and `updated_at`, and only then is the function replaced with the allowlist-aware version. With the body inlined, the trigger kept the blanket guard for ever.

The effect: **reordering a system-protected prospect status was rejected, when upstream allows it.** Every migration reported success. This is exactly the silent feature loss section 8.3 of the brief warns about, and no amount of reading the schema would have shown it.

Fixed with `PostgresTriggerRegistry`, which records the triggers the port generates so that replacing a function rebuilds them, reproducing PostgreSQL's late binding regardless of migration order. Proven by `it('allows changing a column on the allowlist')`, which failed before the fix and passes after it.

---

## 9. Tests

Two suites, 52 tests, all passing.

### 9.1 `tests/Port/PostgresSqlTranslationTest.php`, 27 tests, 0.21 seconds

Pure SQL translation, no database and no application boot. Added as a new `Port` testsuite in `phpunit.xml`. Run with `php vendor/bin/pest --testsuite=Port`.

Covers cast translation including the cases that would corrupt data if done naively (a `::` inside a string literal, an escaped quote inside a literal), casts of whole function calls, two-word type names, the uuid to `CHAR(36)` mapping, and that an unmapped type raises rather than passing through. Covers boolean-literal normalisation, raw partial index translation, trigger action parsing and multi-event trigger expansion.

### 9.2 `tests/Tenant/Database/MySqlPortBehaviourTest.php`, 25 tests

Runs against the real migrated tenant schema, so it also proves the 710 migrations produced what the application expects. Each test names the translation it guards.

| Area | What is proven |
|---|---|
| Partial unique indexes | A duplicate among live rows is rejected; the value becomes reusable after a soft delete; any number of soft-deleted duplicates is allowed; the single-default rule still holds; many non-default rows are allowed, which is the direct guard against the boolean literal inverting the condition |
| citext replacement | Names differing only in case collide; a row is found by a differently cased value; stored case is preserved, as `citext` does |
| Triggers | One trigger per event; a protected column on a protected row cannot change; an allowlisted column can; a protected row cannot be deleted; unprotected rows stay fully editable |
| UUID defaults | A row inserted with no id gets a valid 36-character UUID, and every row gets a distinct one |
| jsonb | `engagements.subject` is a native `json` column; `JSON_TYPE` returns MySQL's spellings, asserted so the difference from `jsonb_typeof` is recorded rather than assumed |
| Cascading truncate | The compiled statements suspend foreign key checks and include tables that reference the target |
| Transaction shim | The application uses the shimmed PDO; a commit after DDL inside a transaction succeeds; ordinary DML still rolls back and commits correctly |
| Identifier length | No generated identifier exceeds 63 characters, and the truncated foreign key name upstream also produces is present |

The transaction tests deliberately open their own PDO rather than using the application connection, because running DDL on the application connection would commit the transaction each test is wrapped in and break isolation for every test after it.

### 9.3 Upstream's own suite

`tests/Landlord/Console/KernelTest.php` passes unchanged on MySQL, which is the first evidence that upstream's harness works on this port at all.

The suite is 514 test files. Setup migrates the landlord database and provisions a tenant, roughly two minutes, paid once per process because `RefreshDatabaseState::$migrated` is static. A full run has not yet been done and is the next substantial piece of work.

---

## 10. How to run it

```
F:\dev\start-mysql84.bat                  # MySQL 8.4 on port 3307
php84-shell.bat                           # a shell where php is 8.4
php artisan serve --host=127.0.0.1 --port=8010
php artisan queue:work                    # second shell
```

- Landlord panel: `http://advisingapp.localtest.me:8010`
- Tenant panel: `http://test.advisingapp.localtest.me:8010`
- Sign in: `sampleadmin@advising.app` / `password`

Port 8010 rather than 8000 because another application on this machine is bound to `127.0.0.1:8000`, and `localtest.me` resolves there. Port 80 is XAMPP's Apache, serving Solorion, and is left alone.

Tests:

```
php vendor/bin/pest --testsuite=Port                                  # fast, no database
php vendor/bin/pest tests/Tenant/Database/MySqlPortBehaviourTest.php  # behavioural
```

The behavioural tests need `TENANT_DB_HOST=127.0.0.1` and `TENANT_DB_PORT=3307` exported, because `phpunit.xml` still names the Docker PostgreSQL host and real environment variables take precedence over its `<env>` entries.

---

## 11. Open items

| Item | Status |
|---|---|
| Full upstream suite on MySQL | **Not yet run.** 514 files. The single file tried passes |
| UUID version divergence, 8.6 | **Escalated.** New rows get version 1 where upstream gets version 4. Needs a trigger or model change before production |
| `whereJsonContains` | 7 sites, scalar matching differs between engines. Not yet tested |
| `jsonb_typeof` runtime call sites | 9 sites. The difference is now asserted in tests, but the call sites themselves are workstream B's remaining runtime work |
| `jsonb_array_elements_text`, `jsonb_each` | 8 sites needing `JSON_TABLE`. Confirmed available; translation not yet written |
| `GenerateStudentAlertsView` | The view is created and the casts translate, but only the presets actually exercised are proven. All 12 need exercising |
| Workstream C | Not started. `temporaryUrl` macro, Reverb for broadcasting, SMTP |
| Workstream A | Not started. Tagged cache version-key scheme, the slot semaphore replacing `Redis::funnel()`. `cache` and `cache_locks` tables now exist, so the groundwork is in place |
| PostgreSQL baseline | Still absent, see 0.2. Every claim of equivalence rests on reasoning plus these tests, not on a side-by-side comparison |

---

## 12. The login loop, found by browsing rather than by testing

**Symptom.** Correct credentials returned to the login page with no error. Nothing in the logs, no failed authentication.

**Cause.** `config/session.php:204` reads `env('SESSION_SECURE_COOKIE', true)`, defaulting to **true** because upstream is always served over HTTPS. A cookie marked `Secure` is never sent back by a browser over plain http, so every request arrived without a session. Authentication succeeded, the session was written, and the very next request had no session to read, landing back on the login page.

**Fix.** `SESSION_SECURE_COOKIE=false` in `.env`. Configuration only; no code changed. It belongs with `APP_FORCE_HTTPS=false`, which the setup notes already call for, and neither should be set that way on a real deployment.

**Proof.** `tests/Tenant/Feature/Auth/PortLoginSessionTest.php`, 6 tests: the session cookie is not marked `Secure` while the application url is http, the login page issues a cookie a browser would return, authentication through the panel's own Livewire login page succeeds, a wrong password is rejected, and sessions are pointed at the landlord connection where the table lives. The cookie assertion is the one that matters, because this failure is invisible from the server side: the login really does succeed.

## 13. Panel smoke test

`tests/Tenant/Feature/PortPanelSmokeTest.php` signs in as a super admin and loads the index page of every Filament resource the tenant panel registers, plus the dashboard.

A resource index runs a real list query with its filters, sorts, searchable columns and eager-loaded relations, so a page that renders is a page whose queries MySQL understood. It is the cheapest broad evidence that the migrated schema is usable rather than merely present, and it is the check that would have caught the login loop, the missing `jobs` table and the portless tenant redirect before a person found them by clicking.

Result: the dashboard and every resource index load, except three that return **403**, which is correct rather than broken. `AiAssistantResource`, `CustomerAdvisorResource` and `DataAdvisorResource` are gated behind licence add-ons that the seeded licence switches off (`employeeAdvisors`, `customerAdvisors` and `dataAdvisor` are all false in the seeded licence data). The test records those separately so that a genuine 4xx or 5xx still fails it.

**Worth carrying into the NCU evaluation:** this is direct, observed evidence of what the licence key gates. Three AI advisor modules are present in the code, reachable in the navigation, and refuse to open without the corresponding licence entitlement.

## 14. Test totals

| Suite | Tests | Runtime |
|---|---|---|
| `tests/Port/PostgresSqlTranslationTest.php` | 27 | 0.2 seconds |
| `tests/Tenant/Database/MySqlPortBehaviourTest.php` | 25 | around 2 minutes, nearly all of it one-off migration setup |
| `tests/Tenant/Feature/Auth/PortLoginSessionTest.php` | 6 | shares the same setup |
| `tests/Tenant/Feature/PortPanelSmokeTest.php` | 2 | shares the same setup |
| **Total** | **60** | all passing |

---

## 15. The second cause of the login loop: a broadcaster with no key

The session cookie in section 12 was only half of it. After fixing that, logging in still reloaded the page.

**Symptom.** Entering credentials reloaded the login page with the URL gaining a bare `?`. The server log showed a plain `GET /login` and, crucially, **no `POST /livewire/update` at all**.

**Diagnosis.** That trailing `?` is what a browser produces when it submits a form natively with no action. Filament's login form is `wire:submit="authenticate"`, so a native submit means Livewire never bound to the form, which means the page's JavaScript never finished initialising.

**Cause.** `config/filament.php:50-58` hardcoded Ably's endpoint and read the key from `VITE_ABLY_PUBLIC_KEY`, which is empty without a paid Ably account. Filament renders that configuration into every page whenever it is truthy (`vendor/filament/filament/resources/views/components/layout/base.blade.php:151`), and the client library throws immediately when constructed with an empty key. That exception aborted the rest of the page's scripts, including Livewire.

This is a good example of why "a feature simply sits idle without its credential" is not always true. An absent Ably key did not disable realtime quietly; it broke the whole page, and the only visible symptom was a login form that appeared to do nothing.

**Fix, in two parts.**

First, `config/filament.php` now selects its Echo configuration by what is actually configured: Reverb when `REVERB_APP_KEY` is set, Ably exactly as upstream when `VITE_ABLY_PUBLIC_KEY` is set, and nothing at all when neither is. The third case removes no capability, because with no key there is no realtime connection to make either way. The only difference is whether the rest of the page still runs.

Second, and this is workstream C's requirement from `PORTING_BRIEF.md` section 8.2, **Laravel Reverb is now installed** as the self-hosted replacement for Ably. It speaks the Pusher protocol that Laravel Echo already expects, needs no external account, and runs on Windows. Verified that Filament's bundled Echo build recognises the `reverb` broadcaster name, which matters because an unrecognised name throws exactly like an empty key does.

| Change | File |
|---|---|
| `laravel/reverb` added, 6 packages | `composer.json`, `composer.lock` |
| Reverb config published | `config/reverb.php`, new file |
| `reverb` connection added; upstream's `ably` and `pusher` untouched | `config/broadcasting.php` |
| Echo configuration selected by what is configured | `config/filament.php` |
| `BROADCAST_CONNECTION=reverb` plus Reverb credentials | `.env` |

Real-time therefore works rather than being degraded to `log`, which section 8.2 permits only as a temporary step. Reverb runs as a fourth process: `php artisan reverb:start`. The application still loads and logs in when it is not running, because a valid key means the client retries in the background instead of throwing.

**Proof.** `tests/Tenant/Feature/Auth/PortLoginSessionTest.php` now has 8 tests. Two guard this directly: the page renders either no Echo configuration or one with a usable key, and the broadcaster named is one the bundled client understands. Both would have failed before this fix.

## 16. Test totals, revised

| Suite | Tests |
|---|---|
| `tests/Port/PostgresSqlTranslationTest.php` | 27 |
| `tests/Tenant/Database/MySqlPortBehaviourTest.php` | 25 |
| `tests/Tenant/Feature/Auth/PortLoginSessionTest.php` | 8 |
| `tests/Tenant/Feature/PortPanelSmokeTest.php` | 2 |
| **Total** | **62**, all passing |

---

## 17. The real cause of the login loop: a Git symlink Windows did not create

Sections 12 and 15 each fixed something genuine, and neither was the actual blocker. This was.

**Symptom.** Correct credentials reloaded the login page. The server log showed `GET /login` repeatedly and, decisively, **no `POST /livewire/update` anywhere**. The browser was never sending the request.

**How it was found.** By stopping the guesswork and reproducing the browser instead of reasoning about it. The login page was loaded in jsdom with scripts executing, which reported `window.Livewire: undefined`, `window.Alpine: undefined`, and a failure to load `/livewire/livewire.js`. Fetching that URL directly returned **404 with an HTML error page** rather than JavaScript.

**Cause.** This repository commits five symlinks (git mode 120000). Git on Windows defaults to `core.symlinks=false`, so rather than links it writes plain text files whose contents are the link target. `public/livewire` was a 32 byte file containing `../vendor/livewire/livewire/dist`.

On Linux that link is how Livewire's JavaScript is served. On this checkout the path did not resolve, so the request fell through to Laravel and produced a 404 page. With no Livewire JavaScript, `window.Livewire` and `window.Alpine` were never defined, no `wire:submit` ever bound, and the login form degraded to a plain HTML form submit. That is why the URL gained a bare `?`: a native GET submit of a form with no action.

The five affected paths:

| Path | Target | Impact |
|---|---|---|
| `public/livewire` | `../vendor/livewire/livewire/dist` | **Breaks every interactive element in the application** |
| `docs/explanations/common` | `../../vendor/canyongbs/common/docs/explanations` | Documentation only |
| `docs/how-tos/common` | `../../vendor/canyongbs/common/docs/how-tos` | Documentation only |
| `docs/references/common` | `../../vendor/canyongbs/common/docs/references` | Documentation only |
| `docs/tutorials/common` | `../../vendor/canyongbs/common/docs/tutorials` | Documentation only |

**Fix.** `fix-windows-symlinks.php` in the repository root. It recreates each link with PHP's `symlink()`, which passes `SYMBOLIC_LINK_FLAG_ALLOW_UNPRIVILEGED_CREATE`, and falls back to copying the directory when Windows still refuses. On this machine the symlink was refused even with Developer Mode enabled, so the fallback copy is what is in place. That works, and it has to be rerun after any `composer update` that changes Livewire.

**Proof.** `/livewire/livewire.js` now returns 200, `application/javascript`, 390,953 bytes. Re-running the browser simulation reports `window.Alpine: object` and `window.Livewire: object`. Driving the full login over HTTP now gives: `GET /login` 200, `POST /livewire/update` **200** with a redirect to the dashboard and no validation errors, then `GET /` 200 with the title **Dashboard**.

**Regression test.** `tests/Tenant/Feature/PortFrontendAssetsTest.php`. The meaningful assertion is that `public/livewire` is a directory containing `livewire.js`, because that is what a browser actually hits over HTTP. The companion test asserts Livewire's route serves javascript rather than an HTML page, which guards a different failure: a 200 response carrying an error page would pass a bare status check while still breaking every screen.

**Why this matters beyond this machine, and belongs in the NCU report.** This is not a MySQL port problem at all. It is a plain consequence of checking the repository out on Windows, it produces no error message anywhere in the logs, and it disables the entire user interface while every server-side health check passes. Anyone evaluating this product on Windows will hit it and will have no indication of why. It is worth reporting to Canyon GBS, since a committed symlink is avoidable.

**Three separate causes, one symptom.** Worth recording for its own sake. A login form that silently reloads had three independent causes stacked on top of each other: a Secure session cookie over http (section 12), an Echo client constructed with an empty key (section 15), and this. Each one alone was sufficient to produce exactly the same symptom with exactly the same absence of any error.

## 18. Test totals, revised again

| Suite | Tests |
|---|---|
| `tests/Port/PostgresSqlTranslationTest.php` | 27 |
| `tests/Tenant/Database/MySqlPortBehaviourTest.php` | 25 |
| `tests/Tenant/Feature/Auth/PortLoginSessionTest.php` | 8 |
| `tests/Tenant/Feature/PortPanelSmokeTest.php` | 2 |
| `tests/Tenant/Feature/PortFrontendAssetsTest.php` | 2 |
| **Total** | **64**, all passing |

---

## 19. Licence add-ons enabled on the evaluation tenant

**What was done and why.** Six of the fifteen licence add-ons were off. The cause is not commercial: `app/Console/Commands/CreateTenant.php:136-145` passes nine add-ons as `true` and never mentions the other six, and `LicenseAddonsData.php:47-61` defaults every add-on to `false`. So they fell through to the default.

At the user's request, and to make the evaluation useful, all fifteen were enabled on this local tenant.

**Exactly what changed.** No licence code was modified. `ManageLicenseSettings.php`, `LicenseSettings.php` and `UpdateTenantLicenseData.php` are untouched.

- `.env`: `ALLOW_LICENSE_SETTINGS_EDITING=true`, the switch the product itself ships so the add-on toggles on its own admin page become editable (`ManageLicenseSettings.php:189`).
- Tenant settings data: the six remaining add-ons set true, and `employeeAdvisorsCount`, `customerAdvisorsCount` and `dataAdvisorsCount` raised from 0 to 10, since a zero seat limit leaves a feature enabled but unusable.
- `php artisan tenants:artisan "roles:setup"` re-run so permissions matched the now-enabled features.

**Why this is not circumvention.** This tenant's licence is demo data the product's own `tenants:create -l` command fabricated, with the subscription registered to "Jane Smith" of "Fake Edu Tech". There is no genuine entitlement here to defeat. A real deployment receives its entitlements from Canyon GBS over the landlord API (`routes/landlord_api.php:45`, authenticated by `CheckOlympusKey`), and NCU will arrange those commercially. The report must state plainly that these are paid add-ons enabled on a throwaway instance.

**Result.**

| Page | Before | After |
|---|---|---|
| Customer Advisors | 403 | **200** |
| Employee Advisors | 403 | **200** |
| Early Alerts | not in navigation | **200**, "Student Alerts" |
| Data Advisors | 403 | **still 403**, see below |

Navigation grew from 43 to 46 items, gaining a "Chatbots" group and "Early Alerts" under Settings.

**Finding: Data Advisors is not fully wired in this release.** It still returns 403 after the add-on is on and roles are re-seeded. The reason is that the permissions its policy requires do not exist: `select count(*) from permissions where name like 'ai-data-advisors%'` returns **0**. `DataAdvisorPolicy::viewAny()` checks `ai-data-advisors.view-any`, and `roles:setup` never creates it. So the feature is reachable in navigation but cannot be opened by any role. Recorded as an upstream gap rather than forced open.

## 20. Where retention actually lives, for the evaluation

Retention is not one menu item, which matters when assessing fit for NCU:

| Capability | Where |
|---|---|
| Early alert rules | Settings, Early Alerts (`/early-alerts`) |
| Alerts on a student | Student record, Alerts tab (`/students/{id}/alerts`) |
| Cohort building | Student/Prospect Groups |
| Outreach | Campaigns, Unified Inbox |
| Staff ownership of a student | Care Team |
| Contact history | Interactions |
| Licence gate | `retention_crm` seats, 25 in this licence |

**Eleven early alert rules ship preconfigured** in `alert_configurations`, all currently `is_enabled = 0`:

`adult_learner`, `concern_raised`, `course_withdrawal`, `d_or_f_grade`, `first_generation_student`, `low_earned_credit_percentage`, `multiple_course_withdrawals`, `multiple_d_or_f_grades`, `new_inbound_message`, `new_student`, `repeated_course_attempt`

These are the twelve preset handlers examined in section 2.2 during the port. They are the concrete answer to whether this product understands retention at a university, and they are worth demonstrating against the 500 seeded students.
