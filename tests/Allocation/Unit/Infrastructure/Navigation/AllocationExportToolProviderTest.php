<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Navigation;

use App\Allocation\Infrastructure\Navigation\AllocationExportToolProvider;
use App\Allocation\Infrastructure\Security\Voter\ExportVoter;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class AllocationExportToolProviderTest extends TestCase
{
    public function testUserWhoMayExportGetsTheTool(): void
    {
        $tools = new AllocationExportToolProvider($this->checker(true))->tools();

        self::assertCount(1, $tools);
        self::assertSame(AllocationExportToolProvider::KEY, $tools[0]->key);
        self::assertSame('app_tools_allocation_export', $tools[0]->route);
        self::assertSame(ToolCategory::DataExport, $tools[0]->category);
        self::assertSame('allocation', $tools[0]->translationDomain);
    }

    public function testUserWhoMayNotExportGetsNoTool(): void
    {
        self::assertSame([], new AllocationExportToolProvider($this->checker(false))->tools());
    }

    private function checker(bool $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->expects(self::once())
            ->method('isGranted')
            ->with(ExportVoter::EXPORT)
            ->willReturn($granted);

        return $checker;
    }
}
