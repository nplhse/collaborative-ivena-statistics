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
    'admin-trix-media' => [
        'path' => './assets/admin-trix-media.js',
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
    '@symfony/ux-live-component' => [
        'path' => './vendor/symfony/ux-live-component/assets/dist/live_controller.js',
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@hotwired/turbo' => [
        'version' => '8.0.23',
    ],
    '@tabler/core' => [
        'version' => '1.4.0',
    ],
    '@tabler/core/dist/css/tabler.min.css' => [
        'version' => '1.4.0',
        'type' => 'css',
    ],
    'debounce' => [
        'version' => '3.0.0',
    ],
    'apexcharts' => [
        'version' => '5.15.0',
    ],
    'fslightbox' => [
        'version' => '3.7.5',
    ],
    'leaflet' => [
        'version' => '1.9.4',
    ],
    'leaflet/dist/leaflet.css' => [
        'version' => '1.9.4',
        'type' => 'css',
    ],
    'list.js' => [
        'version' => '2.3.1',
    ],
    'string-natural-compare' => [
        'version' => '3.0.1',
    ],
    'leaflet/dist/leaflet.min.css' => [
        'version' => '1.9.4',
        'type' => 'css',
    ],
];
