<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    if ('prod' !== $configurator->env()) {
        return;
    }

    $reportUri = trim((string) ($_SERVER['SENTRY_CSP_REPORT_URI'] ?? $_ENV['SENTRY_CSP_REPORT_URI'] ?? getenv('SENTRY_CSP_REPORT_URI') ?: ''));

    if ('' === $reportUri) {
        return;
    }

    $configurator->extension('nelmio_security', [
        'csp' => [
            'report' => [
                'report-uri' => [$reportUri],
            ],
        ],
    ]);
};
