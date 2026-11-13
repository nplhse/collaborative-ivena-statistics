<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Navigation;

use App\Allocation\Infrastructure\Navigation\IndicationReviewToolProvider;
use App\Allocation\Infrastructure\Security\Voter\IndicationRawReviewVoter;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class IndicationReviewToolProviderTest extends TestCase
{
    public function testUserWhoMayViewTheWorklistGetsTheTool(): void
    {
        $tools = new IndicationReviewToolProvider($this->checker(true))->tools();

        self::assertCount(1, $tools);
        self::assertSame(IndicationReviewToolProvider::KEY, $tools[0]->key);
        self::assertSame('app_explore_indication_raw_review_worklist', $tools[0]->route);
        self::assertSame(ToolCategory::Matching, $tools[0]->category);
        self::assertSame('allocation', $tools[0]->translationDomain);
    }

    public function testUserWhoMayNotViewTheWorklistGetsNoTool(): void
    {
        self::assertSame([], new IndicationReviewToolProvider($this->checker(false))->tools());
    }

    private function checker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())
            ->method('isGranted')
            ->with(IndicationRawReviewVoter::VIEW)
            ->willReturn($granted);

        return $checker;
    }
}
