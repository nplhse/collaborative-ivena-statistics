<?php

declare(strict_types=1);

namespace App\Allocation\UI\Twig;

use App\Allocation\Application\Explore\ExploreShowUrlResolver;

final readonly class ExploreEntityLinkExtension
{
    public function __construct(
        private ExploreShowUrlResolver $urlResolver,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'explore_entity_url')]
    public function exploreEntityUrl(?object $entity): ?string
    {
        return $this->urlResolver->resolveUrl($entity);
    }

    /**
     * @param array{label?: string, class?: string, empty?: string} $options
     */
    #[\Twig\Attribute\AsTwigFunction(name: 'explore_entity_link', isSafe: ['html'])]
    public function exploreEntityLink(?object $entity, array $options = []): string
    {
        $empty = $options['empty'] ?? '—';
        if (null === $entity) {
            return $this->escape($empty);
        }

        $label = $this->escape($this->labelFor($entity, $options, $empty));
        $url = $this->urlResolver->resolveUrl($entity);
        if (null === $url) {
            return $label;
        }

        $class = $options['class'] ?? '';
        $classAttr = '' !== $class ? ' class="'.$this->escape($class).'"' : '';

        return sprintf('<a href="%s"%s>%s</a>', $this->escape($url), $classAttr, $label);
    }

    /**
     * @param array{label?: string, class?: string, empty?: string} $options
     */
    private function labelFor(object $entity, array $options, string $empty): string
    {
        if (isset($options['label'])) {
            return $options['label'];
        }

        if ($entity instanceof \Stringable) {
            return (string) $entity;
        }

        return $empty;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
