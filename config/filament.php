<?php

/*
<COPYRIGHT>

    Copyright © 2016-2026, Canyon GBS Inc. All rights reserved.

    Advising App® is licensed under the Elastic License 2.0. For more details,
    see https://github.com/canyongbs/advisingapp/blob/main/LICENSE.

    Notice:

    - You may not provide the software to third parties as a hosted or managed
      service, where the service provides users with access to any substantial set of
      the features or functionality of the software.
    - You may not move, change, disable, or circumvent the license key functionality
      in the software, and you may not remove or obscure any functionality in the
      software that is protected by the license key.
    - You may not alter, remove, or obscure any licensing, copyright, or other notices
      of the licensor in the software. Any use of the licensor’s trademarks is subject
      to applicable law.
    - Canyon GBS Inc. respects the intellectual property rights of others and expects the
      same in return. Canyon GBS® and Advising App® are registered trademarks of
      Canyon GBS Inc., and we are committed to enforcing and protecting our trademarks
      vigorously.
    - The software solution, including services, infrastructure, and code, is offered as a
      Software as a Service (SaaS) by Canyon GBS Inc.
    - Use of this software implies agreement to the license terms and conditions as stated
      in the Elastic License 2.0.

    For more information or inquiries please visit our website at
    https://www.canyongbs.com or contact us via email at legal@canyongbs.com.

</COPYRIGHT>
*/

return [
    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | By uncommenting the Laravel Echo configuration, you may connect Filament
    | to any Pusher-compatible websockets server.
    |
    | This will allow your users to receive real-time notifications.
    |
    */

    /*
     * MySQL port note. Upstream hardcodes the Ably endpoint here and reads the key from
     * VITE_ABLY_PUBLIC_KEY. Filament renders this config into every page whenever it is truthy
     * (vendor/filament/filament/resources/views/components/layout/base.blade.php:151), and the
     * client library throws immediately when handed an empty key. That exception aborts the page's
     * scripts before Livewire initialises, so nothing with wire:submit works: the login form falls
     * back to a plain browser submit and the page simply reloads, with no error shown.
     *
     * So this is now selected by which broadcaster is actually configured:
     *
     *   - Reverb, the self-hosted replacement for Ably that this port uses, when REVERB_APP_KEY is set
     *   - Ably, exactly as upstream, when VITE_ABLY_PUBLIC_KEY is set
     *   - nothing at all when neither is, which leaves the application working rather than broken
     *
     * The third case removes no capability. With no key there is no realtime connection to make
     * either way; the only difference is whether the rest of the page still runs.
     */
    'broadcasting' => [
        'echo' => match (true) {
            filled(env('REVERB_APP_KEY')) => [
                'broadcaster' => 'reverb',
                'key' => env('REVERB_APP_KEY'),
                'wsHost' => env('REVERB_HOST', '127.0.0.1'),
                'wsPort' => (int) env('REVERB_PORT', 8080),
                'wssPort' => (int) env('REVERB_PORT', 8080),
                'forceTLS' => env('REVERB_SCHEME', 'http') === 'https',
                'enabledTransports' => ['ws', 'wss'],
                'disableStats' => true,
            ],
            filled(env('VITE_ABLY_PUBLIC_KEY')) => [
                'broadcaster' => 'pusher',
                'key' => env('VITE_ABLY_PUBLIC_KEY'),
                'wsHost' => 'realtime-pusher.ably.io',
                'wsPort' => 443,
                'disableStats' => true,
                'encrypted' => true,
            ],
            default => null,
        },
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | This is the storage disk Filament will use to put media. You may use any
    | of the disks defined in the `config/filesystems.php`.
    |
    */

    'default_filesystem_disk' => env('FILAMENT_FILESYSTEM_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Livewire Loading Delay
    |--------------------------------------------------------------------------
    |
    | This sets the delay before loading indicators appear.
    |
    | Setting this to 'none' makes indicators appear immediately, which can be
    | desirable for high-latency connections. Setting it to 'default' applies
    | Livewire's standard 200ms delay.
    |
    */

    'livewire_loading_delay' => 'none',
];
