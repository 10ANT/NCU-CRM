<?php

declare(strict_types=1);

/*
 * Part of the MySQL compatibility port tooling. Not upstream code.
 *
 * Repairs the repository's committed symlinks after a Windows checkout.
 *
 * This repository commits five symlinks (git mode 120000). Git on Windows defaults to
 * `core.symlinks=false`, so instead of links it writes plain text files whose contents are the link
 * target, for example `public/livewire` becomes a 32 byte file containing
 * `../vendor/livewire/livewire/dist`.
 *
 * One of those five is not cosmetic. `public/livewire` is how Livewire's JavaScript is served:
 * `/livewire/livewire.js` is expected to resolve through that link into
 * `vendor/livewire/livewire/dist`. With the link broken the request returns a 404 HTML page instead of
 * JavaScript, so `window.Livewire` and `window.Alpine` are never defined and **every** interactive
 * element in the application stops working. The visible symptom is that the login form submits as a
 * plain HTML form and the page appears to reload, with no error anywhere.
 *
 * PHP's `symlink()` is used rather than PowerShell's `New-Item -ItemType SymbolicLink`, because PHP
 * passes `SYMBOLIC_LINK_FLAG_ALLOW_UNPRIVILEGED_CREATE`, which lets the call succeed without
 * administrator rights when Windows Developer Mode is enabled. It is the same mechanism
 * `php artisan storage:link` relies on. If the link still cannot be made, the directory is copied
 * instead, which works but has to be rerun whenever the dependency is updated.
 *
 * Run from the repository root:  php fix-windows-symlinks.php
 */

$root = __DIR__;

$links = [
    'public/livewire' => '../vendor/livewire/livewire/dist',
    'docs/explanations/common' => '../../vendor/canyongbs/common/docs/explanations',
    'docs/how-tos/common' => '../../vendor/canyongbs/common/docs/how-tos',
    'docs/references/common' => '../../vendor/canyongbs/common/docs/references',
    'docs/tutorials/common' => '../../vendor/canyongbs/common/docs/tutorials',
];

function copyDirectory(string $from, string $to): void
{
    @mkdir($to, 0o777, true);

    /** @var SplFileInfo $item */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    ) as $item) {
        $destination = $to . DIRECTORY_SEPARATOR . substr($item->getPathname(), strlen($from) + 1);

        $item->isDir() ? @mkdir($destination, 0o777, true) : @copy($item->getPathname(), $destination);
    }
}

$failed = 0;

foreach ($links as $link => $relativeTarget) {
    $linkPath = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $link);
    $targetPath = realpath(dirname($linkPath) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeTarget));

    if ($targetPath === false || ! is_dir($targetPath)) {
        echo "  skip    {$link} (target does not exist; run composer install first)\n";

        continue;
    }

    if (is_link($linkPath)) {
        echo "  ok      {$link} (already a symlink)\n";

        continue;
    }

    // A correct checkout has a link here. Anything else is the broken text file Git wrote, or a copy
    // made by a previous run of this script.
    if (file_exists($linkPath)) {
        is_dir($linkPath)
            ? array_map('unlink', glob($linkPath . '/*') ?: []) && @rmdir($linkPath)
            : @unlink($linkPath);
    }

    if (@symlink($targetPath, $linkPath)) {
        echo "  linked  {$link} -> {$relativeTarget}\n";

        continue;
    }

    copyDirectory($targetPath, $linkPath);

    if (is_dir($linkPath)) {
        echo "  copied  {$link} (symlink refused; rerun this script after updating the dependency)\n";

        continue;
    }

    echo "  FAILED  {$link}\n";
    $failed++;
}

exit($failed === 0 ? 0 : 1);
