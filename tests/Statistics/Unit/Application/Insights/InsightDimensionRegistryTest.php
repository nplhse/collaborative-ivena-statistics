<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\Application\Insights\InsightDimensionProviderInterface;
use App\Statistics\Application\Insights\InsightDimensionRegistry;
use App\Statistics\Application\Insights\InsightNavPlacement;
use PHPUnit\Framework\TestCase;

final class InsightDimensionRegistryTest extends TestCase
{
    public function testOrdersProvidersAndExposesNavAndTeasers(): void
    {
        $indications = $this->provider(InsightDimensionKey::Indications, InsightNavPlacement::Primary, 10, featured: true);
        $groups = $this->provider(InsightDimensionKey::IndicationGroups, InsightNavPlacement::Nested, 11);
        $assignments = $this->provider(InsightDimensionKey::Assignments, InsightNavPlacement::Primary, 30);
        $infections = $this->provider(InsightDimensionKey::Infections, InsightNavPlacement::Primary, 60);

        $registry = new InsightDimensionRegistry([$infections, $groups, $assignments, $indications]);

        self::assertSame(InsightDimensionKey::Indications, $registry->featured()->key());
        self::assertSame(
            [InsightDimensionKey::Indications, InsightDimensionKey::Assignments, InsightDimensionKey::Infections],
            array_map(static fn (InsightDimensionProviderInterface $provider): InsightDimensionKey => $provider->key(), $registry->primaryNav()),
        );
        self::assertSame(
            [InsightDimensionKey::Assignments, InsightDimensionKey::Infections],
            array_map(static fn (InsightDimensionProviderInterface $provider): InsightDimensionKey => $provider->key(), $registry->overviewTeasers()),
        );
        self::assertSame($indications, $registry->getBySlug('indications'));
        self::assertNull($registry->getBySlug('unknown'));
    }

    public function testUnknownDimensionThrows(): void
    {
        $registry = new InsightDimensionRegistry([]);

        $this->expectException(\InvalidArgumentException::class);
        $registry->get(InsightDimensionKey::Indications);
    }

    public function testFeaturedThrowsWhenMissing(): void
    {
        $registry = new InsightDimensionRegistry([
            $this->provider(InsightDimensionKey::Assignments, InsightNavPlacement::Primary, 30),
        ]);

        $this->expectException(\LogicException::class);
        $registry->featured();
    }

    private function provider(
        InsightDimensionKey $key,
        InsightNavPlacement $placement,
        int $order,
        bool $featured = false,
    ): InsightDimensionProviderInterface {
        $provider = $this->createStub(InsightDimensionProviderInterface::class);
        $provider->method('key')->willReturn($key);
        $provider->method('navPlacement')->willReturn($placement);
        $provider->method('navOrder')->willReturn($order);
        $provider->method('featuredOnOverview')->willReturn($featured);

        return $provider;
    }
}
