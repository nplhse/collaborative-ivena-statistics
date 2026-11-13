<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Tool;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Collects the tools the current user may use, ordered by category and then alphabetically by their
 * translated title in the current locale (Collator, so umlauts sort as in a dictionary).
 */
final readonly class ToolRegistry
{
    /**
     * @param iterable<ToolProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator(ToolProviderInterface::TAG)]
        private iterable $providers,
        private TranslatorInterface $translator,
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

        $collator = new \Collator($this->translator->getLocale());
        $titles = [];
        foreach ($tools as $key => $tool) {
            $titles[$key] = $this->translator->trans($tool->titleKey, [], $tool->translationDomain);
        }

        $tools = array_values($tools);
        usort(
            $tools,
            static fn (Tool $a, Tool $b): int => ($a->category->position() <=> $b->category->position())
                ?: (int) $collator->compare($titles[$a->key], $titles[$b->key])
                ?: $a->key <=> $b->key,
        );

        return $tools;
    }

    /**
     * Asked by the main navigation on every page, so it neither translates nor sorts.
     */
    public function hasTools(): bool
    {
        foreach ($this->providers as $provider) {
            if ([] !== $provider->tools()) {
                return true;
            }
        }

        return false;
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
