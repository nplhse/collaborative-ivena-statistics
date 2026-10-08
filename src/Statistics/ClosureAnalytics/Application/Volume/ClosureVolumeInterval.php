<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Volume;

final readonly class ClosureVolumeInterval
{
    /**
     * @param list<int>                                                 $urgencies
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $segments
     */
    public function __construct(
        public int $id,
        public int $hospitalId,
        public int $departmentId,
        public array $urgencies,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public string $careLevel,
        public int $specialityId = 0,
        public string $scope = 'department',
        public int $eventId = 0,
        public array $segments = [],
    ) {
    }

    public static function fromParts(
        int $id,
        int $hospitalId,
        int $departmentId,
        string $careLevel,
        \DateTimeImmutable $startsAt,
        \DateTimeImmutable $endsAt,
    ): self {
        return new self(
            $id,
            $hospitalId,
            $departmentId,
            ClosureVolumePopulation::urgenciesForCareLevel($careLevel),
            ClosureVolumeClock::wall($startsAt),
            ClosureVolumeClock::wall($endsAt),
            $careLevel,
            0,
            'department',
            $id,
        );
    }

    public function coversUrgency(int $urgency): bool
    {
        return \in_array($urgency, $this->urgencies, true);
    }
}
