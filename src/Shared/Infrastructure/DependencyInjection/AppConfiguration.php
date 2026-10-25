<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class AppConfiguration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('app');

        $rootNode = $treeBuilder->getRootNode();

        $rootNode
            ->children()
                ->scalarNode('title')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('short_title')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('default_locale')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('blog')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('title')
                            ->defaultValue('Blog')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('description')
                            ->defaultValue('Neuigkeiten, Updates und Hintergrundinformationen.')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('content')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('default_locale')
                            ->defaultValue('en')
                            ->cannotBeEmpty()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('meta')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('copyright_start_year')
                            ->defaultValue(2025)
                            ->min(2000)
                        ->end()
                        ->arrayNode('hoster')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('name')
                                    ->defaultValue('')
                                ->end()
                                ->scalarNode('url')
                                    ->defaultValue('')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('import')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('reject_writer')
                            ->values(['csv', 'db'])
                            ->defaultValue('csv')
                        ->end()
                        ->scalarNode('csv_reject_dir')
                            ->defaultValue('var/import_rejects')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('registration')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('blocked_email_domains')
                            ->scalarPrototype()->end()
                            ->defaultValue(['.ru'])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('closure_volume')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('reference_weeks')
                            ->defaultValue(8)
                            ->min(1)
                        ->end()
                        ->integerNode('minimum_reference_slots')
                            ->defaultValue(4)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('storage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('thresholds')
                            ->info('Usage in percent of the capacity reference at which a level starts (ADR 017); exceeded is always 100.')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('notice_percent')->defaultValue(80)->min(1)->max(99)->end()
                                ->integerNode('warning_percent')->defaultValue(90)->min(1)->max(99)->end()
                                ->integerNode('critical_percent')->defaultValue(99)->min(1)->max(99)->end()
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $thresholds): bool => $thresholds['notice_percent'] >= $thresholds['warning_percent'] || $thresholds['warning_percent'] >= $thresholds['critical_percent'])
                                ->thenInvalid('Storage thresholds must increase strictly: notice < warning < critical (< 100). Got %s.')
                            ->end()
                        ->end()
                        ->integerNode('measurement_max_age_hours')
                            ->info('A measurement older than this is stale for Application Health.')
                            ->defaultValue(36)
                            ->min(1)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('feedback')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('spam')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('min_submission_seconds')
                                    ->defaultValue(4)
                                    ->min(1)
                                ->end()
                                ->integerNode('long_message_threshold')
                                    ->defaultValue(1800)
                                    ->min(200)
                                ->end()
                                ->integerNode('max_message_length')
                                    ->defaultValue(4000)
                                    ->min(200)
                                ->end()
                                ->integerNode('anonymous_threshold')
                                    ->defaultValue(6)
                                    ->min(1)
                                ->end()
                                ->integerNode('authenticated_threshold')
                                    ->defaultValue(8)
                                    ->min(1)
                                ->end()
                                ->integerNode('authenticated_score_bonus')
                                    ->defaultValue(2)
                                    ->min(0)
                                ->end()
                                ->arrayNode('keywords')
                                    ->scalarPrototype()->end()
                                    ->defaultValue([
                                        'casino',
                                        'crypto',
                                        'loan',
                                        'viagra',
                                        'seo',
                                        'backlink',
                                        'guest post',
                                        'cialis',
                                        'forex',
                                        'nft',
                                        'weight loss',
                                        'make money',
                                        'click here',
                                    ])
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
