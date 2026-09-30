# Start Here: getting Advising App running on this PC

Your checklist. Four things to install, then five commands. Full detail and reasoning is in `LOCAL_SETUP_NO_DOCKER.md`; this page is just the order of operations.

Docker is not needed. See `CLAUDE.md` section 3 for why.

---

## Part 1: install four things (one time, about 30 minutes)

Nothing here replaces or upgrades anything you already have. XAMPP, its PHP 8.2 and its MySQL are all untouched, and the Solorion project keeps working exactly as it does now.

### 1. PHP 8.4

Download the **PHP 8.4, VS17 x64 Non Thread Safe** zip from https://windows.php.net/download and extract to `C:\php84`.

In `C:\php84`, copy `php.ini-development` to `php.ini`, then set:

```ini
extension_dir = "C:\php84\ext"
memory_limit = 512M
```

and uncomment these lines (remove the leading semicolon):

```ini
extension=bcmath
extension=curl
extension=fileinfo
extension=gd
extension=intl
extension=mbstring
extension=openssl
extension=pdo_pgsql
extension=pgsql
extension=soap
extension=sqlite3
extension=zip
```

Why 8.4 and not your 8.2: 23 packages in `composer.lock` require PHP 8.4.1 or newer, and Laravel 13 needs 8.3 minimum. There is no way around it.

### 2. PostgreSQL

Install PostgreSQL for Windows from https://www.postgresql.org/download/windows/ (the EDB installer). Use port **5432** and remember the superuser password.

It runs on a different port to MySQL and as a separate service, so it does not interfere with XAMPP's MySQL or the Solorion `miraion` database.

Then open pgAdmin or `psql` and run:

```sql
CREATE ROLE advisingapp WITH LOGIN PASSWORD 'password' CREATEDB;
CREATE DATABASE landlord OWNER advisingapp;
```

`CREATEDB` is required because this app creates a database per tenant.

### 3. Memurai (Redis for Windows)

Install the free Developer edition from https://www.memurai.com/get-memurai. Default port 6379.

Redis is genuinely required here. `.env.example` states that file and database cache drivers are not supported, and 50 files call `Cache::tags()`, which only Redis can do.

### 4. Node 24

```bash
nvm install 24.21.0
nvm use 24.21.0
```

You have 22.13.1 and `.nvmrc` asks for 24.21.0. Only needed to build front-end assets.

### 5. Hosts file

Open `C:\Windows\System32\drivers\etc\hosts` **as administrator** and add:

```
127.0.0.1 advisingapp.local
127.0.0.1 test.advisingapp.local
```

---

## Part 2: set up the project

Open the PHP 8.4 shell by double-clicking **`php84-shell.bat`** in this folder. Every command below runs in that window, where `php` means 8.4 and Composer follows it automatically. Other terminals stay on PHP 8.2.

Confirm you are in the right place:

```bash
php -v          # must say 8.4.x
```

### 1. Create your `.env`

```bash
copy .env.example .env
```

Then edit `.env` and change these (the rest can stay as shipped):

```ini
LANDLORD_APP_URL=http://advisingapp.local:8000
APP_FORCE_HTTPS=false

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=landlord
DB_USERNAME=advisingapp
DB_PASSWORD=password

CACHE_DRIVER=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

QUEUE_CONNECTION=database
AUDIT_QUEUE_CONNECTION=database
SESSION_DRIVER=database

FILESYSTEM_DISK=local
FILAMENT_FILESYSTEM_DISK=local
BROADCAST_CONNECTION=log
MAIL_MAILER=log
```

And **delete or comment out** this line, which would break the database session driver:

```ini
SESSION_CONNECTION=session
```

Leave all the AWS, Twilio, Azure, Google and Ably keys empty. Those features will not work, which is expected and gets recorded in the evaluation as untested.

### 2. Install dependencies and build

```bash
composer install
composer require predis/predis     # only if composer.lock does not already have it
npm install
npm run build
php artisan key:generate
php artisan storage:link
```

`storage:link` may need an administrator terminal, because Windows requires privileges for symlinks.

### 3. Set up the database

```bash
php artisan migrate:landlord
```

Then start a queue worker in a **second** php84 shell, because tenant creation dispatches queued jobs:

```bash
php artisan queue:work
```

Back in the first window, create the tenant:

```bash
php artisan tenants:create "NCU Evaluation" test.advisingapp.local -s -a -l -y
```

If that leaves you without a login, run these explicitly:

```bash
php artisan app:create-super-admin --tenant=test.advisingapp.local
php artisan roles:setup --tenant=test.advisingapp.local
```

### 4. Run it

```bash
php artisan serve --host=advisingapp.local --port=8000
```

Keep the queue worker running in the second window.

Then open:

- Landlord admin: http://advisingapp.local:8000
- Tenant (the one you evaluate): http://test.advisingapp.local:8000

---

## If something fails

Check these in order. The first two cover most problems.

1. **`php -v` in the exact terminal that failed.** If it says 8.2, you are not in the php84 shell.
2. **`could not find driver`** means `pdo_pgsql` is not enabled. Run `php --ini` to confirm which `php.ini` is loaded, then check the extension line.
3. **Redirect loop or it insists on HTTPS.** `APP_FORCE_HTTPS` is still `true`.
4. **Login loop across subdomains.** `SESSION_CONNECTION=session` is still in `.env`.
5. **Blank tenant page or "tenant not found".** The domain on the tenant record must match the hostname in the browser exactly.
6. **Cache errors mentioning tags.** Memurai is not running, or `CACHE_DRIVER` is not `redis`.
7. **Something mentions a licence key.** Expected. Note what it gates and move on. Do not disable it, the Elastic Licence forbids that.
8. **A feature errors on a missing AWS, Twilio or OpenAI credential.** Expected. Log as untested, not broken.

Full explanations for all of the above are in `LOCAL_SETUP_NO_DOCKER.md` sections 5 and 6.
