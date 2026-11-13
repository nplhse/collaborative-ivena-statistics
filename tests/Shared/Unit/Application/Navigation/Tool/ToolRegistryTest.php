<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\Navigation\Tool;

use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use App\Shared\Application\Navigation\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class ToolRegistryTest extends TestCase
{
    public function testWithoutToolsTheRegistryIsEmpty(): void
    {
        $registry = new ToolRegistry([$this->provider()]);

        self::assertSame([], $registry->availableTools());
        self::assertSame([], $registry->groupedByCategory());
        self::assertFalse($registry->hasTools());
    }

    public function testToolsAreOrderedByCategoryThenPositionThenKey(): void
    {
        $registry = new ToolRegistry([
            $this->provider($this->tool('prep', ToolCategory::DataPreparation, 0)),
            $this->provider($this->tool('review_b', ToolCategory::Matching, 20), $this->tool('review_a', ToolCategory::Matching, 20)),
            $this->provider($this->tool('validation', ToolCategory::Matching, 10)),
        ]);

        self::assertSame(
            ['validation', 'review_a', 'review_b', 'prep'],
            array_map(static fn (Tool $tool): string => $tool->key, $registry->availableTools()),
        );
        self::assertTrue($registry->hasTools());
    }

    public function testGroupsFollowTheCategoryOrderAndSkipEmptyCategories(): void
    {
        $registry = new ToolRegistry([
            $this->provider($this->tool('prep', ToolCategory::DataPreparation, 0)),
        ]);

        $groups = $registry->groupedByCategory();

        self::assertCount(1, $groups);
        self::assertSame(ToolCategory::DataPreparation, $groups[0]->category);
        self::assertSame(['prep'], array_map(static fn (Tool $tool): string => $tool->key, $groups[0]->tools));
    }

    public function testDuplicateKeysAreRejected(): void
    {
        $registry = new ToolRegistry([
            $this->provider($this->tool('review', ToolCategory::Matching, 0)),
            $this->provider($this->tool('review', ToolCategory::DataPreparation, 0)),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Tool key "review" is registered more than once.');

        $registry->availableTools();
    }

    private function tool(string $key, ToolCategory $category, int $position): Tool
    {
        return new Tool($key, 'title', 'description', 'shared', 'app_tools_index', 'tabler:tool', $category, $position);
    }

    private function provider(Tool ...$tools): ToolProviderInterface
    {
        return new readonly class(array_values($tools)) implements ToolProviderInterface {
            /**
             * @param list<Tool> $tools
             */
            public function __construct(private array $tools)
            {
            }

            #[\Override]
            public function tools(): array
            {
                return $this->tools;
            }
        };
    }
}
