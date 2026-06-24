<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExplorerChoiceGrouper
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @template T
     *
     * @param list<T>             $items
     * @param list<string>        $groupOrder          translation keys in display order
     * @param callable(T): string $groupTranslationKey
     * @param callable(T): string $label
     * @param callable(T): string $value
     *
     * @return array<string, array<string, string>>
     */
    public function groupChoices(
        array $items,
        array $groupOrder,
        callable $groupTranslationKey,
        callable $label,
        callable $value,
        string $locale = 'en',
    ): array {
        /** @var array<string, list<array{label: string, value: string}>> $buckets */
        $buckets = [];

        foreach ($items as $item) {
            $groupKey = $groupTranslationKey($item);
            $buckets[$groupKey][] = [
                'label' => $label($item),
                'value' => $value($item),
            ];
        }

        $collator = new \Collator($locale);
        $grouped = [];

        foreach ($groupOrder as $groupKey) {
            if (!isset($buckets[$groupKey])) {
                continue;
            }

            $grouped[$this->translator->trans($groupKey)] = $this->sortedChoices(
                $buckets[$groupKey],
                $collator,
            );
            unset($buckets[$groupKey]);
        }

        foreach ($buckets as $groupKey => $choices) {
            $grouped[$this->translator->trans($groupKey)] = $this->sortedChoices(
                $choices,
                $collator,
            );
        }

        return $grouped;
    }

    /**
     * @param list<array{label: string, value: string}> $choices
     *
     * @return array<string, string>
     */
    private function sortedChoices(array $choices, \Collator $collator): array
    {
        usort(
            $choices,
            static fn (array $left, array $right): int => $collator->compare($left['label'], $right['label']),
        );

        $sorted = [];
        foreach ($choices as $choice) {
            $sorted[$choice['label']] = $choice['value'];
        }

        return $sorted;
    }
}
