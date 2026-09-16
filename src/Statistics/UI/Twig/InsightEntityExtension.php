<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig;

use App\Statistics\Application\Insights\InsightEntityUrlResolver;

final readonly class InsightEntityExtension
{
    public function __construct(
        private InsightEntityUrlResolver $urlResolver,
    ) {
    }

    #[\Twig\Attribute\AsTwigFunction(name: 'insight_entity_url')]
    public function insightEntityUrl(?object $entity): ?string
    {
        return $this->urlResolver->resolve($entity);
    }
}
