<?php

declare(strict_types=1);

namespace App\Statistics\Application\Panel\Distribution;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class GenderCodeLabelMapper implements CodeLabelMapperInterface
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

        $projection = AllocationStatsGenderProjectionCode::tryFrom($code);
        if (!$projection instanceof AllocationStatsGenderProjectionCode) {
            return $this->translator->trans('statistics.distribution.unknown_code');
        }

        $gender = match ($projection) {
            AllocationStatsGenderProjectionCode::Male => AllocationGender::MALE,
            AllocationStatsGenderProjectionCode::Female => AllocationGender::FEMALE,
            AllocationStatsGenderProjectionCode::Other => AllocationGender::OTHER,
        };

        return $this->translator->trans($gender->label());
    }
}
