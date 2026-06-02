<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class AppExtension extends Extension
{
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new AppConfiguration();
        $config = $this->processConfiguration($configuration, $configs);

        // General settings
        $container->setParameter('app.title', $config['title']);
        $container->setParameter('app.short_title', $config['short_title']);
        $container->setParameter('app.default.locale', $config['default_locale']);
        $container->setParameter('app.blog.title', $config['blog']['title']);
        $container->setParameter('app.blog.description', $config['blog']['description']);

        // Import settings
        $importConfig = $config['import'];
        $container->setParameter('app.import.reject_writer', $importConfig['reject_writer']);
        $container->setParameter('app.import.csv_reject_dir', $importConfig['csv_reject_dir']);

        // Feedback spam settings
        $spamConfig = $config['feedback']['spam'];
        $container->setParameter('app.feedback.spam.min_submission_seconds', $spamConfig['min_submission_seconds']);
        $container->setParameter('app.feedback.spam.long_message_threshold', $spamConfig['long_message_threshold']);
        $container->setParameter('app.feedback.spam.anonymous_threshold', $spamConfig['anonymous_threshold']);
        $container->setParameter('app.feedback.spam.authenticated_threshold', $spamConfig['authenticated_threshold']);
        $container->setParameter('app.feedback.spam.authenticated_score_bonus', $spamConfig['authenticated_score_bonus']);
        $container->setParameter('app.feedback.spam.keywords', $spamConfig['keywords']);
    }
}
