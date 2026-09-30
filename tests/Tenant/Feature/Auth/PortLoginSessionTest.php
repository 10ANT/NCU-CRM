<?php

declare(strict_types=1);

/*
 * Tests for the MySQL compatibility port. Not upstream code.
 *
 * Guards the login loop that appeared once the application was actually browsed.
 *
 * The symptom was that submitting correct credentials silently returned to the login page. The cause
 * was not authentication at all: `config/session.php:204` defaults `secure` to true, because upstream
 * is always served over HTTPS. A Secure cookie is never sent back by the browser over plain http, so
 * every request arrived without a session, the freshly authenticated session was discarded, and the
 * user landed back on the login page with no error to explain it.
 *
 * That failure mode is worth a permanent test because it is completely invisible from the server's
 * point of view: the login succeeds, the session is written, and the response is a normal redirect.
 * Only the cookie flag reveals it.
 */

use AdvisingApp\Authorization\Filament\Pages\Auth\Login;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\get;

describe('session cookies must be usable over the scheme the application is served on', function () {
    it('does not mark the session cookie Secure when the application url is not https', function () {
        // Over http a Secure cookie is dropped by the browser, which produces a login page that
        // silently reloads instead of signing the user in.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            expect(config('session.secure'))->toBeTrue();

            return;
        }

        expect(config('session.secure'))->toBeFalse(
            'session.secure is true while the application is served over http, so the session cookie '
            . 'will never be returned by the browser and logging in will appear to do nothing. Set '
            . 'SESSION_SECURE_COOKIE=false for http, or serve the application over https.'
        );
    });

    it('issues a session cookie that the browser will actually send back', function () {
        $response = get('/login');

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

        expect($cookie)->not->toBeNull('No session cookie was issued by the login page.');

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            expect($cookie->isSecure())->toBeFalse();
        }

        // httpOnly protects the cookie from scripts and is unrelated to the loop, but losing it would
        // be a security regression, so it is asserted here while the cookie is in hand.
        expect($cookie->isHttpOnly())->toBeTrue();
    });
});

describe('the page must not ship a broadcaster it cannot construct', function () {
    // The second cause of the login loop, and a nastier one than the cookie.
    //
    // config/filament.php hardcoded Ably's endpoint and read the key from VITE_ABLY_PUBLIC_KEY, which
    // is empty without a paid Ably account. Filament renders that config into every page whenever it
    // is truthy, and the client library throws the moment it is handed an empty key. That exception
    // aborted the page's scripts before Livewire initialised, so wire:submit never bound and the
    // login form fell back to a plain browser submit. The page reloaded, no error was shown, and the
    // server logs showed only a GET of /login with no /livewire/update at all.
    //
    // The rule this guards: either a usable broadcaster is configured, or none is rendered. Never a
    // broadcaster with no key.

    it('renders no Echo configuration at all, or one with a usable key', function () {
        $html = get('/login')->getContent();

        if (! str_contains((string) $html, 'window.Echo')) {
            // Nothing rendered is a valid state: no realtime, but the application works.
            expect(config('filament.broadcasting.echo'))->toBeNull();

            return;
        }

        $echo = config('filament.broadcasting.echo');

        expect($echo)->toBeArray()
            ->and($echo['key'] ?? null)->not->toBeEmpty(
                'Filament is rendering an Echo configuration with an empty key. The client throws on '
                . 'construction, which aborts every script on the page, and the first visible symptom '
                . 'is that logging in silently reloads the login page.'
            );
    });

    it('names a broadcaster the bundled Echo client actually understands', function () {
        $echo = config('filament.broadcasting.echo');

        if ($echo === null) {
            expect(true)->toBeTrue();

            return;
        }

        // An unrecognised broadcaster name throws in the client exactly like an empty key does.
        expect($echo['broadcaster'] ?? null)->toBeIn(['reverb', 'pusher', 'socket.io', 'null']);
    });
});

describe('logging in', function () {
    it('authenticates a user through the panel login page', function () {
        $user = User::factory()->create(['password' => bcrypt('port-test-password')]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'remember' => false,
                'password' => 'port-test-password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        expect(Auth::check())->toBeTrue()
            ->and(Auth::id())->toBe($user->getKey());
    });

    it('rejects a wrong password', function () {
        $user = User::factory()->create(['password' => bcrypt('port-test-password')]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'remember' => false,
                'password' => 'not-the-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors();

        expect(Auth::check())->toBeFalse();
    });
});

describe('the session store the port moved onto MySQL', function () {
    it('names the landlord connection for sessions, where the sessions table lives', function () {
        // Upstream stores sessions in Redis. The port moves them to the database, and the tenant
        // databases deliberately have no sessions table: upstream creates one and drops it again in
        // 2024_12_19_165310_drop_sessions_table.php. So the connection must name the landlord.
        //
        // Only the connection is asserted, not the driver: phpunit.xml pins SESSION_DRIVER to array
        // for the test run, while the connection still comes from .env and is what would be wrong if
        // this were misconfigured. config/session.php defaults it to the literal 'default', which is
        // not a connection this application defines, so leaving it unset breaks sessions outright.
        expect(config('session.connection'))->toBe('landlord');
    });

    it('has a sessions table on that connection', function () {
        expect(DB::connection(config('session.connection'))->getSchemaBuilder()->hasTable(config('session.table')))
            ->toBeTrue();
    });
});
