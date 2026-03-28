<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Allocation\Domain\Enum\AllocationUrgency;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class UrgencyCodeLabelMapper implements CodeLabelMapperInterface
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    #[\Override]
    public function label(?int $code): string
    {
        if (null === $code) {
            return $this->translator->trans('statistics.distribution.unknown_code');
        }

        $urgency = AllocationUrgency::tryFrom($code);
        if (!$urgency instanceof AllocationUrgency) {
            return $this->translator->trans('statistics.distribution.unknown_code');
        }

        return $this->translator->trans($urgency->label());
    }
}
