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
                                    ->defaultValue(['casino', 'crypto', 'loan', 'viagra', 'seo', 'backlink', 'guest post'])
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
