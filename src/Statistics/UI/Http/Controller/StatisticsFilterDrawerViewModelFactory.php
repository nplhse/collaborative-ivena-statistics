<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Infrastructure\Repository\InfectionRepository;
use App\Statistics\AnalysisExplorer\Application\AnalysisFilterChoiceProvider;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsFilterDrawerViewModelFactory
{
    public function __construct(
        private StatisticsFilterDrawerStateFactory $stateFactory,
        private AnalysisFilterChoiceProvider $filterChoiceProvider,
        private InfectionRepository $infectionRepository,
        private TranslatorInterface $translator,
        private StatisticsDrawerFilterBadgePresenter $badgePresenter,
    ) {
    }

    /**
     * @return array{
     *   values: array<string, string>,
     *   activeCount: int,
     *   filterKeys: list<string>,
     *   badges: list<array{label: string, value: string}>,
     *   choices: array{
     *     gender: array<int|string, string>,
     *     age_group: array<int|string, string>,
     *     urgency: array<int|string, string>,
     *     department: array<int|string, string>,
     *     speciality: array<int|string, string>,
     *     infection: array<int|string, string>
     *   }
     * }
     */
    public function create(Request $request): array
    {
        $state = $this->stateFactory->fromRequest($request);
        $choices = [
            'gender' => $this->filterDrawerChoices($this->filterChoiceProvider->genderChoices()),
            'age_group' => $this->filterDrawerChoices($this->filterChoiceProvider->ageGroupChoices()),
            'urgency' => $this->filterDrawerChoices($this->urgencyChoices()),
            'department' => $this->filterDrawerChoices($this->filterChoiceProvider->departmentChoices()),
            'speciality' => $this->filterDrawerChoices($this->filterChoiceProvider->specialityChoices()),
            'infection' => $this->infectionChoices(),
        ];

        return [
            'values' => $state['values'],
            'activeCount' => $state['activeCount'],
            'filterKeys' => StatisticsQueryKeys::DRAWER_FILTERS,
            'badges' => $this->badgePresenter->present($state['values'], $choices),
            'choices' => $choices,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function infectionChoices(): array
    {
        $choices = [];
        foreach ($this->infectionRepository->findBy([], ['name' => 'ASC']) as $infection) {
            $choices[(int) $infection->getId()] = (string) $infection->getName();
        }

        return $choices;
    }

    /**
     * @return array<int, string>
     */
    private function urgencyChoices(): array
    {
        $choices = [];
        foreach (AllocationUrgency::cases() as $urgency) {
            $choices[(string) $urgency->value] = $this->translator->trans('allocation.urgency.'.$urgency->value);
        }

        return $choices;
    }

    /**
     * @param array<int|string, string> $choices
     *
     * @return array<int|string, string>
     */
    private function filterDrawerChoices(array $choices): array
    {
        unset($choices['unknown']);

        return $choices;
    }
}
