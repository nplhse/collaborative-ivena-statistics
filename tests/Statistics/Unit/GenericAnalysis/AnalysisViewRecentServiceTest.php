<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\GenericAnalysis;

use App\Statistics\GenericAnalysis\Application\AnalysisViewRecentService;
use App\Statistics\GenericAnalysis\Application\AnalysisViewUsageTracker;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AnalysisViewRecentServiceTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private AnalysisViewRecentService $recentService;

    private AnalysisViewUsageTracker $usageTracker;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->recentService = $container->get(AnalysisViewRecentService::class);
        $this->usageTracker = $container->get(AnalysisViewUsageTracker::class);
    }

    public function testLastUsedReturnsRecentlyOpenedView(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $this->usageTracker->recordSystemViewOpen($user, 'allocations_by_month');

        $views = $this->recentService->lastUsed($user, 5);

        self::assertCount(1, $views);
        self::assertSame('allocations_by_month', $views[0]->key);
    }

    public function testMostFrequentPrefersRepeatedlyOpenedView(): void
    {
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $this->usageTracker->recordSystemViewOpen($user, 'allocations_by_month');
        $this->usageTracker->recordSystemViewOpen($user, 'urgency_distribution_with_share');
        $this->usageTracker->recordSystemViewOpen($user, 'allocations_by_month');
        $this->usageTracker->recordSystemViewOpen($user, 'allocations_by_month');

        $views = $this->recentService->mostFrequent($user, 5);

        self::assertNotEmpty($views);
        self::assertSame('allocations_by_month', $views[0]->key);
    }
}
