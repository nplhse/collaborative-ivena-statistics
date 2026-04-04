<?php

declare(strict_types=1);

namespace App\Statistics\Application\Mapping;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Maps 0 = unknown (NULL), 1 = no (false), 2 = yes (true) for distribution dimensions.
 */
final readonly class TriStateBoolValueMapper implements ValueMapper
{
    public function __construct(
        private TranslatorInterface $translator,
        private string $translationPrefix,
    ) {
    }

    #[\Override]
    public function label(?int $value): string
    {
        $suffix = match ($value) {
            1 => 'no',
            2 => 'yes',
            default => 'unknown',
        };

        return $this->translator->trans($this->translationPrefix.'.'.$suffix);
    }
}
