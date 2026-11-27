<?php

namespace Deployer;

require 'recipe/symfony.php';
require 'contrib/cachetool.php';

set('repository', 'https://github.com/nplhse/collaborative-ivena-statistics.git');
set('writable_mode', 'chmod');
set('env', [
    'APP_ENV' => 'prod',
    'APP_DEBUG' => '0',
]);

set('messenger_systemd_service', 'messenger.service');
set('messenger_restart_on_deploy', true);

set('cachetool_args', '--web --web-path={{deploy_path}}/current/public --web-url={{web_url}}');

// Releases kept on the server: the current one and one to roll back to (`dep rollback`). Deployer's default is 10.
set('keep_releases', 2);

desc('Point Uberspace u8 DocumentRoot symlinks at current/public');
task('deploy:uberspace:webroot', function () {
    if (!test('command -v uberspace >/dev/null 2>&1')) {
        return;
    }

    $deployPath = get('deploy_path');
    $domain = parse_url(get('web_url'), PHP_URL_HOST) ?: '';
    $linkNames = array_values(array_unique(array_filter(['html', $domain])));

    foreach ($linkNames as $linkName) {
        $linkPath = $deployPath.'/'.$linkName;
        $before = trim(run('if [ -L '.escapeshellarg($linkPath).' ]; then readlink '.escapeshellarg($linkPath).'; elif [ -e '.escapeshellarg($linkPath).' ]; then echo directory; else echo missing; fi'));
        if ($before === 'current/public') {
            continue;
        }

        run('cd '.escapeshellarg($deployPath).' && rm -f html/nocontent.html 2>/dev/null || true');
        run('cd '.escapeshellarg($deployPath).' && if [ -d '.escapeshellarg($linkName).' ] && [ ! -L '.escapeshellarg($linkName).' ]; then rm -rf '.escapeshellarg($linkName).'; fi');
        run('cd '.escapeshellarg($deployPath).' && ln -sfn current/public '.escapeshellarg($linkName));
    }
});

add('shared_files', [
    '.env.local',
]);
add('shared_dirs', [
    'var/imports',
    // Reference catalog packages (ADR 020): an export must survive the next deploy.
    'var/reference',
    'var/geo/hospital-isochrones',
    // Media storage (ADR 039). shared/public/uploads/media of older releases stays until v0.4: the previous release
    // still serves it, and media:migrate-storage copies from it.
    'var/media',
    // Archives of data extract exports (ADR 045): downloadable for seven days, across deploys.
    'var/research/exports',
    // Maintenance flag (#1680, ADR 046): the old and the new release must see the same switch.
    'var/maintenance',
]);
add('writable_dirs', [
    'var/imports',
    'var/reference',
    'var/geo/hospital-isochrones',
    'var/media',
    'var/research/exports',
    'var/maintenance',
]);

/*
 * For security reasons we're importing a local hosts.yaml file that includes
 * hostnames etc. for our deployments.
 * For details see: https://deployer.org/docs/8.x/hosts#yaml-inventory
 */
import('hosts.yaml');

desc('Compile asset map for production');
task('deploy:assets', function () {
    run('cd {{release_or_current_path}} && {{bin/console}} asset-map:compile {{console_options}}');
});

desc('Warm up Symfony cache');
task('deploy:cache:warmup', function () {
    run('cd {{release_or_current_path}} && {{bin/console}} cache:warmup {{console_options}}');
});

after('deploy:vendors', 'deploy:assets');
after('deploy:assets', 'deploy:cache:warmup');

// Through systemd: the unit has Restart=always, so messenger:stop-workers would bring the old worker back after
// a few seconds, while the migrations run.
desc('Stop the Messenger systemd user service before the migrations');
task('messenger:stop', function () {
    if (!get('messenger_restart_on_deploy')) {
        return;
    }
    if (!has('previous_release')) {
        writeln('<comment>No previous release; skipping messenger:stop</comment>');

        return;
    }
    run('systemctl --user stop '.escapeshellarg(get('messenger_systemd_service')));
    set('messenger_stopped_by_deploy', true);
});

desc('Restart Messenger systemd user service');
task('messenger:restart', function () {
    if (!get('messenger_restart_on_deploy')) {
        return;
    }
    $service = get('messenger_systemd_service');
    run('systemctl --user restart '.escapeshellarg($service));
});

desc('Start the Messenger worker again when a failed deploy had stopped it');
task('messenger:restart-after-failure', function () {
    if (!get('messenger_stopped_by_deploy', false)) {
        return;
    }
    invoke('messenger:restart');
});

// Maintenance mode (#1680, ADR 046): on before the migrations, off after the switch to the new release.
// `dep deploy -o keep_maintenance=true` keeps it on for one-time commands; switch it off with `dep maintenance:off`.
// A failed deploy leaves it on: the old release then keeps users away from a half-migrated schema.
set('keep_maintenance', false);

desc('Switch the maintenance mode on: only administrators can use the application');
task('maintenance:on', function () {
    run('cd {{release_or_current_path}} && {{bin/console}} app:maintenance on {{console_options}}');
    set('maintenance_switched_on_by_deploy', true);
});

desc('Switch the maintenance mode off');
task('maintenance:off', function () {
    run('cd {{release_or_current_path}} && {{bin/console}} app:maintenance off {{console_options}}');
});

desc('Switch the maintenance mode off after the deploy, unless -o keep_maintenance=true');
task('maintenance:off-after-deploy', function () {
    if (filter_var(get('keep_maintenance'), \FILTER_VALIDATE_BOOL)) {
        warning('Maintenance mode stays on. Run the one-time commands, check the application, then: dep maintenance:off');

        return;
    }
    invoke('maintenance:off');
});

desc('Point out that a failed deploy left the maintenance mode on');
task('maintenance:report-after-failure', function () {
    if (!get('maintenance_switched_on_by_deploy', false)) {
        return;
    }
    warning('The deploy failed and the maintenance mode stays on. Fix and deploy again, or switch it off: dep maintenance:off');
});

desc('Reset OPcache after deploy');
task('deploy:cache:opcache', function () {
    if (test('command -v uberspace >/dev/null 2>&1')) {
        run('uberspace web php reload');

        return;
    }

    invoke('cachetool:clear:opcache');
});

// Attach tasks from recipes& contrib to default workflow
after('deploy:symlink', 'deploy:uberspace:webroot');
after('deploy:uberspace:webroot', 'deploy:cache:opcache');

before('deploy:publish', 'database:migrate');
before('database:migrate', 'messenger:stop');
before('database:migrate', 'maintenance:on');
after('deploy:publish', 'messenger:restart');
after('deploy:publish', 'maintenance:off-after-deploy');

after('deploy:failed', 'messenger:restart-after-failure');
after('deploy:failed', 'maintenance:report-after-failure');
after('deploy:failed', 'deploy:unlock');

desc('Move media files from the old upload directory into the media storage (ADR 039)');
task('media:migrate-storage', function () {
    run('cd {{release_or_current_path}} && {{bin/console}} app:media:migrate-storage --legacy-dir={{deploy_path}}/shared/public/uploads/media {{console_options}}');
});

// Before the switch, so the new release starts with every file in the storage. It copies and leaves the old
// directory alone, so `dep rollback` keeps working; a failure stops the deploy before the switch.
after('database:migrate', 'media:migrate-storage');

// Not part of every deploy: once old URLs in content point to /media, the previous release cannot show those
// images any more. Run it after the release is accepted (upgrade guide, v0.2.x → v0.3).
desc('Replace old /uploads/media URLs in posts and pages with the URLs of the moved media (ADR 039)');
task('media:rewrite-content-urls', function () {
    run('cd {{current_path}} && {{bin/console}} app:media:rewrite-content-urls --apply {{console_options}}');
});

set('content_analyze_page_images_options', '--backfill-dimensions --migrate-size');

desc('Analyze page images, backfill media dimensions, and migrate layout sizes on the server');
task('content:analyze-page-images', function () {
    $options = get('content_analyze_page_images_options');
    run('cd {{current_path}} && {{bin/console}} app:content:analyze-page-images '.$options.' {{console_options}}');
});
