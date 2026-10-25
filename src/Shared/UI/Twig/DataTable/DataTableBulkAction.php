<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

/**
 * One action of a DataTable bulk toolbar. All texts are already translated.
 *
 * With a confirmation, the button opens a dialog whose title names the selected count:
 * `confirmTitleOne` for one row, `confirmTitleOther` (with a `%count%` placeholder) for several.
 * Without a confirmation the button submits the selection directly.
 *
 * @psalm-suppress PossiblyUnusedProperty Consumed by data_table/_bulk_actions.html.twig.
 */
final readonly class DataTableBulkAction
{
    /** @psalm-suppress PossiblyUnusedMethod Built by list controllers that offer bulk actions. */
    public function __construct(
        public string $name,
        public string $label,
        public ?string $icon = null,
        public string $variant = 'outline-primary',
        public ?string $confirmTitleOne = null,
        public ?string $confirmTitleOther = null,
        public ?string $confirmMessage = null,
        public ?string $confirmLabel = null,
        public string $confirmVariant = 'primary',
    ) {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid DataTable bulk action name "%s".', $name));
        }
        if ((null === $confirmTitleOne) !== (null === $confirmTitleOther)) {
            throw new \InvalidArgumentException(\sprintf('Bulk action "%s" needs both confirmation titles or none.', $name));
        }
    }

    /** @psalm-suppress PossiblyUnusedMethod Called from data_table templates. */
    public function requiresConfirmation(): bool
    {
        return null !== $this->confirmTitleOther;
    }
}
