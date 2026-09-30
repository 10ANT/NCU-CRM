<?php

declare(strict_types=1);

/*
 * Tests for the MySQL compatibility port. Not upstream code.
 *
 * A breadth-first smoke test: sign in as a super admin and load the index page of every Filament
 * resource the tenant panel registers.
 *
 * The point is coverage of the port rather than depth of any one feature. A resource index page runs a
 * real list query, with the table's filters, sorts, searchable columns and eager-loaded relations, so a
 * page that renders is a page whose queries MySQL understood. That is the cheapest broad check
 * available that the 710 migrated tables are actually usable, as opposed to merely present.
 *
 * It is also the check that would have caught the login loop, the missing jobs table and the portless
 * tenant redirect before a person had to find them by clicking.
 */

use Filament\Facades\Filament;

use function Pest\Laravel\get;
use function Tests\asSuperAdmin;

it('loads the tenant panel dashboard', function () {
    asSuperAdmin();

    get('/')->assertSuccessful();
});

it('loads the index page of every registered resource', function () {
    asSuperAdmin();

    $panel = Filament::getPanel('admin');

    Filament::setCurrentPanel($panel);

    $failures = [];
    $gated = [];
    $loaded = 0;
    $checked = 0;

    foreach ($panel->getResources() as $resource) {
        $pages = $resource::getPages();

        if (! isset($pages['index'])) {
            continue;
        }

        try {
            $url = $resource::getUrl('index', panel: 'admin');
        } catch (Throwable $exception) {
            // A resource whose index url cannot even be built is a real problem, so it is recorded
            // rather than skipped.
            $failures[$resource] = 'could not build url: ' . $exception->getMessage();

            continue;
        }

        $checked++;

        try {
            $response = get($url);
            $status = $response->getStatusCode();

            // 403 is a correct answer, not a failure. Several resources are gated behind licence
            // add-ons that the seeded licence switches off, so a super admin is still refused. Those
            // are recorded separately rather than counted as breakage, because treating them as
            // failures would be wrong and silencing all 4xx would hide genuine routing problems.
            if ($status === 403) {
                $gated[$resource] = $url;

                continue;
            }

            if ($status >= 400) {
                $failures[$resource] = $url . ' returned ' . $status;

                continue;
            }

            $loaded++;
        } catch (Throwable $exception) {
            $failures[$resource] = $url . ' threw ' . $exception::class . ': ' . $exception->getMessage();
        }
    }

    // If these ever drop the test is silently proving less than it appears to, so both are asserted.
    expect($checked)->toBeGreaterThan(10)
        ->and($loaded)->toBeGreaterThan(10);

    expect($failures)->toBe(
        [],
        "Resource index pages failed to load:\n" . collect($failures)
            ->map(fn (string $reason, string $resource): string => "  {$resource}: {$reason}")
            ->implode("\n")
    );
});
