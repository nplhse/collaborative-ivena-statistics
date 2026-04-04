<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Statistics\Application\Panel\PanelDefinition;
use Symfony\Component\OptionsResolver\Exception\ExceptionInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class DistributionPageConfigResolver
{
    /**
     * @param array{
     *     route_name: string,
     *     section_key: string,
     *     panels: list<array<string, mixed>>,
     *     default_panel_key?: string|null,
     * } $options
     *
     * @throws ExceptionInterface
     */
    public function resolve(array $options): DistributionPageConfig
    {
        $page = $this->configurePageResolver()->resolve($options);

        $panels = [];
        foreach ($page['panels'] as $panelRow) {
            $panels[] = $this->createPanelDefinition($panelRow);
        }

        return new DistributionPageConfig(
            routeName: $page['route_name'],
            sectionKey: $page['section_key'],
            panels: $panels,
            defaultPanelKey: $page['default_panel_key'],
        );
    }

    /**
     * @param array<string, mixed> $panelOptions
     *
     * @throws ExceptionInterface
     */
    public function createPanelDefinition(array $panelOptions): PanelDefinition
    {
        $p = $this->configurePanelResolver()->resolve($panelOptions);

        return new PanelDefinition(
            key: $p['key'],
            type: $p['type'],
            dimensionKind: DimensionKind::from($p['dimension_kind']),
            dimensionField: $p['dimension_field'],
            dimensionLabel: $p['dimension_label'],
            groupByField: $p['group_by_field'],
            groupByLabel: $p['group_by_label'],
            filters: $p['filters'],
            options: $p['options'],
            controls: $p['controls'],
            filterDefaults: $p['filter_defaults'],
        );
    }

    private function configurePageResolver(): OptionsResolver
    {
        $resolver = new OptionsResolver();
        $resolver->setDefined(['route_name', 'section_key', 'panels', 'default_panel_key']);
        $resolver->setRequired(['route_name', 'section_key', 'panels']);
        $resolver->setAllowedTypes('route_name', 'string');
        $resolver->setAllowedTypes('section_key', 'string');
        $resolver->setAllowedTypes('panels', 'array');
        $resolver->setDefault('default_panel_key', null);
        $resolver->setAllowedTypes('default_panel_key', ['string', 'null']);

        $resolver->setNormalizer('route_name', static fn (Options $options, string $value): string => '' !== trim($value) ? $value : throw new \InvalidArgumentException('route_name must be non-empty.'));

        $resolver->setNormalizer('section_key', static fn (Options $options, string $value): string => '' !== trim($value) ? $value : throw new \InvalidArgumentException('section_key must be non-empty.'));

        $resolver->setNormalizer('panels', function (Options $options, array $value): array {
            if ([] === $value) {
                throw new \InvalidArgumentException('panels must contain at least one panel.');
            }

            return $value;
        });

        return $resolver;
    }

    private function configurePanelResolver(): OptionsResolver
    {
        $dimensionValues = array_map(
            static fn (DimensionKind $k): string => $k->value,
            DimensionKind::cases(),
        );

        $resolver = new OptionsResolver();
        $resolver->setDefined([
            'key',
            'type',
            'dimension_kind',
            'dimension_field',
            'dimension_label',
            'group_by_field',
            'group_by_label',
            'filters',
            'options',
            'controls',
            'filter_defaults',
        ]);
        $resolver->setRequired([
            'key',
            'type',
            'dimension_kind',
            'dimension_field',
            'dimension_label',
            'filters',
            'options',
            'controls',
        ]);
        $resolver->setDefaults([
            'group_by_field' => null,
            'group_by_label' => null,
            'filter_defaults' => [],
        ]);

        $resolver->setAllowedTypes('key', 'string');
        $resolver->setAllowedTypes('type', 'string');
        $resolver->setAllowedTypes('dimension_kind', 'string');
        $resolver->setAllowedTypes('dimension_field', 'string');
        $resolver->setAllowedTypes('dimension_label', 'string');
        $resolver->setAllowedTypes('group_by_field', ['string', 'null']);
        $resolver->setAllowedTypes('group_by_label', ['string', 'null']);
        $resolver->setAllowedTypes('filters', 'array');
        $resolver->setAllowedTypes('options', 'array');
        $resolver->setAllowedTypes('controls', 'array');
        $resolver->setAllowedTypes('filter_defaults', 'array');

        $resolver->setAllowedValues('dimension_kind', $dimensionValues);

        $resolver->setNormalizer('key', static fn (Options $options, string $value): string => '' !== trim($value) ? $value : throw new \InvalidArgumentException('panel key must be non-empty.'));

        return $resolver;
    }
}
