<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\RuleBasedAnalysisViewRecommendationService;
use App\Statistics\GenericAnalysis\Registry\AnalysisViewRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RuleBasedAnalysisViewRecommendationServiceTest extends KernelTestCase
{
    public function testRecommendForUserReturnsFeaturedViews(): void
    {
        self::bootKernel();
        $service = self::getContainer()->get(RuleBasedAnalysisViewRecommendationService::class);

        $views = $service->recommendForUser(null, 3);

        self::assertCount(3, $views);
        foreach ($views as $view) {
            self::assertTrue($view->isFeatured);
        }
    }

    public function testRecommendForUserReturnsOnlyRegisteredFeaturedViews(): void
    {
        self::bootKernel();
        $registry = self::getContainer()->get(AnalysisViewRegistry::class);
        $service = self::getContainer()->get(RuleBasedAnalysisViewRecommendationService::class);

        $views = $service->recommendForUser(null, 6);
        $registryKeys = array_map(static fn ($view) => $view->key, $registry->all());

        self::assertNotEmpty($views);
        foreach ($views as $view) {
            self::assertContains($view->key, $registryKeys);
            self::assertTrue($view->isFeatured);
        }
    }
}
