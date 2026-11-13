<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\Navigation\Tool;

use App\Shared\Application\Navigation\Tool\Tool;
use App\Shared\Application\Navigation\Tool\ToolCategory;
use App\Shared\Application\Navigation\Tool\ToolProviderInterface;
use App\Shared\Application\Navigation\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ToolRegistryTest extends TestCase
{
    public function testWithoutToolsTheRegistryIsEmpty(): void
    {
        $registry = new ToolRegistry([$this->provider()], $this->translator());

        self::assertSame([], $registry->availableTools());
        self::assertSame([], $registry->groupedByCategory());
        self::assertFalse($registry->hasTools());
    }

    public function testToolsAreOrderedByCategoryThenByTranslatedTitleInTheLocale(): void
    {
        $registry = new ToolRegistry([
            $this->provider($this->tool('prep', ToolCategory::DataPreparation)),
            $this->provider($this->tool('zuordnung', ToolCategory::Matching), $this->tool('aerzte', ToolCategory::Matching)),
            $this->provider($this->tool('abgleich', ToolCategory::Matching)),
        ], $this->translator('de', ['zuordnung' => 'Zuordnung', 'aerzte' => 'Ärzte-Review', 'abgleich' => 'Abgleich', 'prep' => 'Aufbereitung']));

        self::assertSame(
            ['abgleich', 'aerzte', 'zuordnung', 'prep'],
            array_map(static fn (Tool $tool): string => $tool->key, $registry->availableTools()),
            'German collation puts "Ärzte" after "Abgleich", not after "Zuordnung" as a byte sort would.',
        );
        self::assertTrue($registry->hasTools());
    }

    public function testEqualTitlesFallBackToTheKey(): void
    {
        $registry = new ToolRegistry([
            $this->provider($this->tool('b', ToolCategory::Matching), $this->tool('a', ToolCategory::Matching)),
        ], $this->translator('en', ['a' => 'Review', 'b' => 'Review']));

        self::assertSame(['a', 'b'], array_map(static fn (Tool $tool): string => $tool->key, $registry->availableTools()));
    }

    public function testGroupsFollowTheCategoryOrderAndSkipEmptyCategories(): void
    {
        $registry = new ToolRegistry([
            $this->provider($this->tool('prep', ToolCategory::DataPreparation)),
        ], $this->translator());

        $groups = $registry->groupedByCategory();

        self::assertCount(1, $groups);
        self::assertSame(ToolCategory::DataPreparation, $groups[0]->category);
        self::assertSame(['prep'], array_map(static fn (Tool $tool): string => $tool->key, $groups[0]->tools));
    }

    public function testDuplicateKeysAreRejected(): void
    {
        $registry = new ToolRegistry([
            $this->provider($this->tool('review', ToolCategory::Matching)),
            $this->provider($this->tool('review', ToolCategory::DataPreparation)),
        ], $this->translator());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Tool key "review" is registered more than once.');

        $registry->availableTools();
    }

    private function tool(string $key, ToolCategory $category): Tool
    {
        return new Tool($key, $key, 'description', 'shared', 'app_tools_index', 'tabler:tool', $category);
    }

    /**
     * @param array<string, string> $titles translation by key; unknown keys translate to themselves
     */
    private function translator(string $locale = 'en', array $titles = []): TranslatorInterface
    {
        return new readonly class($locale, $titles) implements TranslatorInterface {
            /**
             * @param array<string, string> $titles
             */
            public function __construct(private string $locale, private array $titles)
            {
            }

            /**
             * @param array<string, mixed> $parameters
             */
            #[\Override]
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return $this->titles[$id] ?? $id;
            }

            #[\Override]
            public function getLocale(): string
            {
                return $this->locale;
            }
        };
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
