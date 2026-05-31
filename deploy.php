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
]);
add('writable_dirs', [
    'var/imports',
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

desc('Gracefully stop Messenger workers on the previous release');
task('messenger:stop', function () {
    if (!get('messenger_restart_on_deploy')) {
        return;
    }
    if (!has('previous_release')) {
        writeln('<comment>No previous release; skipping messenger:stop-workers</comment>');

        return;
    }
    run('cd {{previous_release}} && {{bin/console}} messenger:stop-workers {{console_options}}');
});

desc('Restart Messenger systemd user service');
task('messenger:restart', function () {
    if (!get('messenger_restart_on_deploy')) {
        return;
    }
    $service = get('messenger_systemd_service');
    run('systemctl --user restart '.escapeshellarg($service));
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
before('deploy:symlink', 'messenger:stop');
after('deploy:symlink', 'deploy:uberspace:webroot');
after('deploy:uberspace:webroot', 'deploy:cache:opcache');

before('deploy:publish', 'database:migrate');
after('deploy:publish', 'messenger:restart');

after('deploy:failed', 'deploy:unlock');
