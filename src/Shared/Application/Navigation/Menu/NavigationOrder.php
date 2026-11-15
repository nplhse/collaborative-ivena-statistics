<?php

declare(strict_types=1);

namespace App\Shared\Application\Navigation\Menu;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The order of the entries on one level of a menu (ADR 035): weight ascending, then the translated label in
 * the current locale, then the id.
 */
final readonly class NavigationOrder
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @template T of NavGroup|NavItem
     *
     * @param list<T> $entries
     *
     * @return list<T>
     */
    public function sort(array $entries): array
    {
        $collator = new \Collator($this->translator->getLocale());
        $labels = [];
        foreach ($entries as $entry) {
            $labels[$entry->id] = $entry->label?->trans($this->translator) ?? '';
        }

        usort(
            $entries,
            static fn (NavGroup|NavItem $a, NavGroup|NavItem $b): int => ($a->weight <=> $b->weight)
                ?: (int) $collator->compare($labels[$a->id], $labels[$b->id])
                ?: $a->id <=> $b->id,
        );

        return $entries;
    }
}
