<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use App\Allocation\Infrastructure\Repository\IndicationGroupRepository;
use App\Allocation\Infrastructure\Repository\IndicationNormalizedRepository;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class IndicationCompareSubjectPickerViewModelFactory
{
    public function __construct(
        private IndicationNormalizedRepository $indicationRepository,
        private IndicationGroupRepository $groupRepository,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<array{type: string, id: int, label: string}>
     */
    public function buildMenuItems(): array
    {
        $groupSuffix = $this->translator->trans('stats.indication.compare.picker_group_suffix', [], 'statistics');
        $items = [];

        foreach ($this->groupRepository->getDatalist() as $group) {
            $items[] = [
                'type' => IndicationSubjectType::Group->value,
                'id' => $group['id'],
                'label' => $group['label'].$groupSuffix,
            ];
        }

        foreach ($this->indicationRepository->getDatalist() as $indication) {
            $items[] = [
                'type' => IndicationSubjectType::Single->value,
                'id' => $indication['id'],
                'label' => $indication['label'],
            ];
        }

        usort(
            $items,
            static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']),
        );

        return $items;
    }
}
