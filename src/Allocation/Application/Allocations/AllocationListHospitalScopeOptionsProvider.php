<?php

declare(strict_types=1);

namespace App\Allocation\Application\Allocations;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AllocationListHospitalScopeOptionsProvider
{
    public function __construct(
        private AllocationListHospitalScopeResolver $hospitalScopeResolver,
        private HospitalRepository $hospitalRepository,
        private StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
        private TranslatorInterface $translator,
    ) {
    }

    public function canUseFilter(User $user): bool
    {
        return $this->hospitalScopeResolver->canUseFilter($user);
    }

    /**
     * @return array{
     *     scopeLabel: string,
     *     hospitals: list<array{id: int, name: string}>,
     *     allClinicsLabel: string,
     * }
     */
    public function optionsFor(User $user, ?string $locale = null): array
    {
        $hospitals = $this->hospitalRepository->findAccessibleHospitalSummaries($user);

        return [
            'scopeLabel' => $this->hospitalScopeLabelResolver->groupLabel($user, $locale),
            'hospitals' => $hospitals,
            'allClinicsLabel' => $this->translator->trans('label.import.filter.all_hospitals', [], null, $locale),
        ];
    }
}
