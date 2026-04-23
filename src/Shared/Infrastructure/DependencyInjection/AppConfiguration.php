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
            ->end()
        ;

        return $treeBuilder;
    }
}
