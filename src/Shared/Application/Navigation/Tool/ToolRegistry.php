<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Collects the tools the current user may use, ordered by category, position and key.
 */
final readonly class ToolRegistry
{
    /**
     * @param iterable<ToolProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(ToolProviderInterface::TAG)]
        private iterable $providers,
    ) {
    }

    /**
     * @return list<Tool>
     */
    public function availableTools(): array
    {
        $tools = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->tools() as $tool) {
                if (\array_key_exists($tool->key, $tools)) {
                    throw new \LogicException(\sprintf('Tool key "%s" is registered more than once.', $tool->key));
                }

                $tools[$tool->key] = $tool;
            }
        }

        $tools = array_values($tools);
        usort($tools, static fn (Tool $a, Tool $b): int => [$a->category->position(), $a->position, $a->key]
            <=> [$b->category->position(), $b->position, $b->key]);

        return $tools;
    }

    public function hasTools(): bool
    {
        return [] !== $this->availableTools();
    }

    /**
     * @return list<ToolGroup>
     */
    public function groupedByCategory(): array
    {
        $byCategory = [];
        foreach ($this->availableTools() as $tool) {
            $byCategory[$tool->category->value][] = $tool;
        }

        $groups = [];
        foreach (ToolCategory::cases() as $category) {
            if (isset($byCategory[$category->value])) {
                $groups[] = new ToolGroup($category, $byCategory[$category->value]);
            }
        }

        return $groups;
    }
}
