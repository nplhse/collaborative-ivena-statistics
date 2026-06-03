<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    'admin-kpi' => [
        'path' => './assets/admin-kpi.js',
        'entrypoint' => true,
    ],
    'admin-page-form' => [
        'path' => './assets/admin-page-form.js',
        'entrypoint' => true,
    ],
    'error_page' => [
        'path' => './assets/error-page.js',
        'entrypoint' => true,
    ],
    'monitoring' => [
        'path' => './assets/monitoring.js',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.18',
    ],
    '@tabler/core' => [
        'version' => '1.4.0',
    ],
    '@tabler/core/dist/css/tabler.min.css' => [
        'version' => '1.4.0',
        'type' => 'css',
    ],
    'debounce' => [
        'version' => '2.2.0',
    ],
    'apexcharts' => [
        'version' => '5.3.6',
    ],
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    'fslightbox' => [
        'version' => '3.7.5',
    ],
];
