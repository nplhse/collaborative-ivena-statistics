<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

use App\Allocation\Application\Explore\ExploreFilterOptionsProvider;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Statistics\Application\Mapping\AllocationStatsGenderProjectionCode;
use App\Statistics\Application\Mapping\AllocationStatsTransportTypeProjectionCode;
use App\Statistics\Application\Mapping\StatisticsAgeGroupFilter;
use App\Statistics\GenericAnalysis\Registry\DimensionRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AnalysisFilterChoiceProvider
{
    public function __construct(
        private ExploreFilterOptionsProvider $referenceData,
        private DimensionRegistry $dimensionRegistry,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function departmentChoices(): array
    {
        return $this->choicesFromIdNameList($this->referenceData->departments());
    }

    /**
     * @return array<int, string>
     */
    public function specialityChoices(): array
    {
        return $this->choicesFromIdNameList($this->referenceData->specialities());
    }

    /**
     * @return array<int, string>
     */
    public function assignmentChoices(): array
    {
        return $this->choicesFromIdNameList($this->referenceData->assignments());
    }

    /**
     * @return array<int, string>
     */
    public function urgencyChoices(): array
    {
        $choices = [];
        foreach (AllocationUrgency::cases() as $case) {
            $choices[$case->value] = $this->translator->trans($case->label(), [], 'messages');
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
            $choices[$case->value] = $this->translator->trans($case->labelTranslationKey(), [], 'messages');
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
            $choices[$case->value] = $this->translator->trans($case->labelTranslationKey(), [], 'messages');
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
            $choices[$key] = $this->translator->trans($translationKey, [], 'statistics');
        }

        $dimension = $this->dimensionRegistry->get('age_group');
        foreach ($dimension->fixedBuckets as $bucket) {
            $bucketKey = (string) $bucket;
            if ('unknown' === $bucketKey || StatisticsAgeGroupFilter::isAggregate($bucketKey)) {
                continue;
            }

            $choices[$bucketKey] = $this->translator->trans(
                StatisticsAgeGroupFilter::bucketTranslationKey($bucketKey),
                [],
                'messages',
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
            '' => $this->translator->trans('label.all', [], 'messages'),
            '1' => $this->translator->trans('label.yes', [], 'messages'),
            '0' => $this->translator->trans('label.no', [], 'messages'),
        ];

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    public function indicationChoices(): array
    {
        return $this->choicesFromIndicationList($this->referenceData->indications());
    }

    /**
     * @return array<int, string>
     */
    public function indicationGroupChoices(): array
    {
        return $this->choicesFromIdNameList($this->referenceData->indicationGroups());
    }

    /**
     * @param list<array{id: int, name: string}> $rows
     *
     * @return array<int, string>
     */
    private function choicesFromIdNameList(array $rows): array
    {
        $choices = [];
        foreach ($rows as $row) {
            $choices[$row['id']] = $row['name'];
        }

        return $choices;
    }

    /**
     * @param list<array{id: int, code: int, name: string}> $rows
     *
     * @return array<int, string>
     */
    private function choicesFromIndicationList(array $rows): array
    {
        $choices = [];
        foreach ($rows as $row) {
            $label = $row['name'];
            if (0 !== $row['code']) {
                $label .= ' ('.$row['code'].')';
            }

            $choices[$row['id']] = $label;
        }

        return $choices;
    }
}
