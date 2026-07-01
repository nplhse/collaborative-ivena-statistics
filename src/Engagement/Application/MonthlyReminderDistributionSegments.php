<?php

declare(strict_types=1);

namespace App\Engagement\Application;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Engagement\Application\Dto\MonthlyReminderSegment;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class MonthlyReminderDistributionSegments
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param array<string, int> $genderCounts
     *
     * @return list<MonthlyReminderSegment>
     */
    public function genderSegments(array $genderCounts, int $total, string $locale): array
    {
        $colors = [
            AllocationGender::MALE->value => '#206bc4',
            AllocationGender::FEMALE->value => '#d63384',
            AllocationGender::OTHER->value => '#7950f2',
        ];

        $segments = [];
        foreach (AllocationGender::cases() as $case) {
            $count = $genderCounts[$case->value] ?? 0;
            $segments[] = new MonthlyReminderSegment(
                $this->translator->trans($case->label(), [], 'messages', $locale),
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
                $colors[$case->value] ?? '#667382',
            );
        }

        return $segments;
    }

    /**
     * @param array<int, int> $urgencyCounts
     *
     * @return list<MonthlyReminderSegment>
     */
    public function urgencySegments(array $urgencyCounts, int $total, string $locale): array
    {
        $colors = [
            AllocationUrgency::EMERGENCY->value => '#d63939',
            AllocationUrgency::INPATIENT->value => '#f59f00',
            AllocationUrgency::OUTPATIENT->value => '#2fb344',
        ];

        $segments = [];
        foreach (AllocationUrgency::cases() as $case) {
            $count = $urgencyCounts[$case->value] ?? 0;
            $segments[] = new MonthlyReminderSegment(
                $this->translator->trans($case->label(), [], 'messages', $locale),
                $total > 0 ? round(100 * $count / $total, 1) : 0.0,
                $colors[$case->value] ?? '#667382',
            );
        }

        return $segments;
    }
}
