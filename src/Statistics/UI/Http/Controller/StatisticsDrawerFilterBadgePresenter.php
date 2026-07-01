<?php

declare(strict_types=1);

namespace App\Statistics\UI\Http\Controller;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class StatisticsDrawerFilterBadgePresenter
{
    /** @var array<string, string> */
    private const array LABEL_KEYS = [
        'gender' => 'label.gender',
        'urgency' => 'label.urgency',
        'age_group' => 'label.age_group',
        'department' => 'label.department',
        'speciality' => 'label.speciality',
        'requiresResus' => 'label.requires_resus',
        'requiresCathlab' => 'label.requires_cathlab',
        'isVentilated' => 'label.is_ventilated',
        'isShock' => 'label.is_shock',
        'isCPR' => 'label.is_cpr',
        'isPregnant' => 'label.is_pregnant',
        'isWorkAccident' => 'label.is_work_accident',
        'isInfectious' => 'label.is_infectious',
        'infection' => 'label.infection',
    ];

    /** @var list<string> */
    private const array BOOLEAN_KEYS = [
        'requiresResus',
        'requiresCathlab',
        'isVentilated',
        'isShock',
        'isCPR',
        'isPregnant',
        'isWorkAccident',
        'isInfectious',
    ];

    /** @var list<string> */
    private const array CHOICE_KEYS = [
        'gender',
        'urgency',
        'age_group',
        'department',
        'speciality',
        'infection',
    ];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, string>                    $values
     * @param array<string, array<int|string, string>> $choices
     *
     * @return list<array{label: string, value: string}>
     */
    public function present(array $values, array $choices): array
    {
        $badges = [];

        foreach (self::LABEL_KEYS as $key => $labelKey) {
            $raw = trim($values[$key] ?? '');
            if ('' === $raw) {
                continue;
            }

            $badges[] = [
                'label' => $this->translator->trans($labelKey, [], 'messages'),
                'value' => $this->valueLabel($key, $raw, $choices),
            ];
        }

        usort($badges, static fn (array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $badges;
    }

    /**
     * @param array<string, array<int|string, string>> $choices
     */
    private function valueLabel(string $key, string $raw, array $choices): string
    {
        if (\in_array($key, self::BOOLEAN_KEYS, true)) {
            return filter_var($raw, FILTER_VALIDATE_BOOLEAN)
                ? $this->translator->trans('label.yes', [], 'messages')
                : $this->translator->trans('label.no', [], 'messages');
        }

        if (\in_array($key, self::CHOICE_KEYS, true)) {
            $choiceSet = $choices[$key] ?? [];
            if (isset($choiceSet[$raw])) {
                return $choiceSet[$raw];
            }
            if (ctype_digit($raw) && isset($choiceSet[(int) $raw])) {
                return $choiceSet[(int) $raw];
            }
        }

        if ('urgency' === $key && ctype_digit($raw)) {
            return $this->translator->trans('allocation.urgency.'.$raw, [], 'allocation');
        }

        return $raw;
    }
}
