<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Allocation\Infrastructure\Repository\AssignmentRepository;
use App\Allocation\Infrastructure\Repository\DepartmentRepository;
use App\Allocation\Infrastructure\Repository\SpecialityRepository;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsUrgencyProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisFilterChoiceProvider
{
    public function __construct(
        private DepartmentRepository $departmentRepository,
        private SpecialityRepository $specialityRepository,
        private AssignmentRepository $assignmentRepository,
        private DimensionRegistry $dimensionRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function departmentChoices(): array
    {
        return $this->entityChoices($this->departmentRepository->findBy([], ['name' => 'ASC']));
    }

    /**
     * @return array<int, string>
     */
    public function specialityChoices(): array
    {
        return $this->entityChoices($this->specialityRepository->findBy([], ['name' => 'ASC']));
    }

    /**
     * @return array<int, string>
     */
    public function assignmentChoices(): array
    {
        return $this->entityChoices($this->assignmentRepository->findBy([], ['name' => 'ASC']));
    }

    /**
     * @return array<int, string>
     */
    public function urgencyChoices(): array
    {
        $choices = [];
        foreach (AllocationStatsUrgencyProjectionCode::cases() as $case) {
            $choices[$case->value] = $this->translator->trans($case->labelTranslationKey());
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    public function transportTypeChoices(): array
    {
        $choices = [];
        foreach (AllocationStatsTransportTypeProjectionCode::cases() as $case) {
            $choices[$case->value] = $this->translator->trans($case->labelTranslationKey());
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    public function genderChoices(): array
    {
        $choices = [];
        foreach (AllocationStatsGenderProjectionCode::cases() as $case) {
            $choices[$case->value] = $this->translator->trans($case->labelTranslationKey());
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    public function ageGroupChoices(): array
    {
        $choices = [];
        foreach (StatisticsAgeGroupFilter::AGGREGATE_TRANSLATION_KEYS as $key => $translationKey) {
            $choices[$key] = $this->translator->trans($translationKey);
        }

        $dimension = $this->dimensionRegistry->get('age_group');
        foreach ($dimension->fixedBuckets as $bucket) {
            $bucketKey = (string) $bucket;
            if ('unknown' === $bucketKey || StatisticsAgeGroupFilter::isAggregate($bucketKey)) {
                continue;
            }

            $choices[$bucketKey] = $this->translator->trans(
                StatisticsAgeGroupFilter::bucketTranslationKey($bucketKey),
            );
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    public function booleanTriStateChoices(): array
    {
        /** @var array<string, string> $choices */
        $choices = [
            '' => $this->translator->trans('label.all'),
            '1' => $this->translator->trans('label.yes'),
            '0' => $this->translator->trans('label.no'),
        ];

        return $choices;
    }

    /**
     * @param list<object> $entities
     *
     * @return array<int, string>
     */
    private function entityChoices(array $entities): array
    {
        $choices = [];
        foreach ($entities as $entity) {
            if (!method_exists($entity, 'getId') || !method_exists($entity, 'getName')) {
                continue;
            }

            $choices[(int) $entity->getId()] = (string) $entity->getName();
        }

        return $choices;
    }
}
