<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Application\Insights;

use App\Allocation\Domain\Entity\Assignment;
use App\Allocation\Domain\Entity\IndicationGroup;
use App\Statistics\Application\Insights\InsightEntityUrlResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InsightEntityUrlResolverTest extends TestCase
{
    public function testResolvesCatalogEntitiesAndIgnoresOthers(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_stats_insights_show', ['dimension' => 'assignments', 'id' => 12])
            ->willReturn('/statistics/insights/assignments/12');

        $assignment = new Assignment();
        $idProperty = new \ReflectionProperty(Assignment::class, 'id');
        $idProperty->setValue($assignment, 12);

        $resolver = new InsightEntityUrlResolver($urlGenerator);

        self::assertSame('/statistics/insights/assignments/12', $resolver->resolve($assignment));
        self::assertNull($resolver->resolve(null));
        self::assertNull($resolver->resolve(new \stdClass()));
    }

    public function testResolvesIndicationGroups(): void
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('app_stats_insights_show', ['dimension' => 'indication-groups', 'id' => 7])
            ->willReturn('/statistics/insights/indication-groups/7');

        $group = new IndicationGroup();
        $idProperty = new \ReflectionProperty(IndicationGroup::class, 'id');
        $idProperty->setValue($group, 7);

        self::assertSame(
            '/statistics/insights/indication-groups/7',
            new InsightEntityUrlResolver($urlGenerator)->resolve($group),
        );
    }
}
