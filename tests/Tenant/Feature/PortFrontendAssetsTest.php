<?php

declare(strict_types=1);

/*
 * Tests for the MySQL compatibility port. Not upstream code.
 *
 * Guards the failure that made the whole application look broken while every server-side check passed.
 *
 * This repository commits five symlinks (git mode 120000). Git on Windows defaults to
 * `core.symlinks=false`, so it writes plain text files containing the link target instead. One of
 * them, `public/livewire`, is how Livewire's JavaScript is served: `/livewire/livewire.js` resolves
 * through it into `vendor/livewire/livewire/dist`.
 *
 * With that link broken the request returned a 404 HTML error page instead of JavaScript. Nothing
 * logged an error, every route resolved correctly, the login page rendered, and authentication worked
 * when driven directly. But `window.Livewire` and `window.Alpine` were never defined in the browser,
 * so no `wire:submit` ever bound, the login form submitted as a plain HTML form, and the page simply
 * reloaded. The only trace was a `GET /login` in the server log with no `POST /livewire/update`
 * anywhere near it.
 *
 * These assertions are deliberately about content type and body, not just status. A 200 response
 * containing an HTML error page would satisfy a status check while still breaking every page.
 */

use function Pest\Laravel\get;

describe('the front end assets the application cannot work without', function () {
    // Note on coverage: this first test exercises Livewire's route, which serves the file from the
    // vendor directory and therefore passes even when public/livewire is broken. It guards the route
    // itself. The second test is the one that actually catches the Windows checkout failure, because
    // over a real HTTP server the static path is what a browser hits.
    it('serves Livewire javascript as javascript, not as an html error page', function () {
        $response = get('/livewire/livewire.js');

        $response->assertSuccessful();

        $contentType = strtolower((string) $response->headers->get('content-type'));

        // toBeTrue carries a message; toContain's second argument is another needle, not a message.
        expect(str_contains($contentType, 'javascript'))->toBeTrue(
            'Livewire javascript is being served as ' . $contentType . ' instead of javascript. '
            . 'Without it nothing in the application is interactive and logging in silently reloads '
            . 'the page.'
        );

        $body = $response->streamedContent() ?: (string) $response->getContent();

        expect($body)->not->toContain('<!DOCTYPE html>')
            ->and(strlen($body))->toBeGreaterThan(100_000);
    });

    it('has a real directory behind public/livewire rather than a stray text file', function () {
        $path = public_path('livewire');

        expect(file_exists($path))->toBeTrue('public/livewire is missing entirely.');

        // A Windows checkout leaves a small text file holding the link target. A working checkout has
        // either a symlink or, after running the repair script, a real directory.
        expect(is_dir($path))->toBeTrue(
            'public/livewire is a file, not a directory. Git on Windows wrote the symlink target as '
            . 'text instead of creating a link. Run php fix-windows-symlinks.php.'
        );

        expect(file_exists($path . DIRECTORY_SEPARATOR . 'livewire.js'))->toBeTrue();
    });
});
