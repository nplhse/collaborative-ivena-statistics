<?php

namespace Deployer;

require 'recipe/symfony.php';

set('repository', 'https://github.com/nplhse/collaborative-ivena-statistics.git');
set('writable_mode', 'chmod');
set('env', [
    'APP_ENV' => 'prod',
    'APP_DEBUG' => '0',
]);

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

// Attach tasks from recipes& contrib to default workflow
before('deploy:publish', 'database:migrate');

after('deploy:failed', 'deploy:unlock');
