<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\DTO;

final readonly class ClosureEventRow
{
    /**
     * @param list<ClosureEventChildPreview> $children
     */
    public function __construct(
        public string $key,
        public ClosureEventType $type,
        public int $hospitalId,
        public string $hospitalName,
        public ?string $sourceGroupId,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public int $closureCount,
        public int $summedMinutes,
        public int $actualMinutes,
        public int $observedMinutes,
        public array $children = [],
        public string $hospitalPublicId = '',
    ) {
    }

    public function grouped(): bool
    {
        return ClosureEventType::Group === $this->type;
    }

    public function clustered(): bool
    {
        return ClosureEventType::Cluster === $this->type;
    }

    public function hasChildren(): bool
    {
        return ClosureEventType::Single !== $this->type;
    }

    /**
     * @return list<string>
     */
    public function getSpecialities(): array
    {
        return $this->uniqueNames(static fn (ClosureEventChildPreview $child): string => $child->specialityName);
    }

    /**
     * @return list<string>
     */
    public function getDepartments(): array
    {
        return $this->uniqueNames(static fn (ClosureEventChildPreview $child): string => $child->departmentName);
    }

    /**
     * @return list<string>
     */
    public function getCareLevels(): array
    {
        return $this->uniqueNames(static fn (ClosureEventChildPreview $child): string => $child->careLevel);
    }

    /**
     * @return list<string>
     */
    public function getReasons(): array
    {
        return $this->uniqueNames(static fn (ClosureEventChildPreview $child): string => $child->reason);
    }

    /**
     * @return list<string>
     */
    public function getClosureUnits(): array
    {
        return $this->uniqueNames(static fn (ClosureEventChildPreview $child): ?string => $child->closureUnit);
    }

    /**
     * @param \Closure(ClosureEventChildPreview): ?string $value
     *
     * @return list<string>
     */
    private function uniqueNames(\Closure $value): array
    {
        $values = [];
        foreach ($this->children as $child) {
            $item = $value($child);
            if (null !== $item && '' !== $item && !\in_array($item, $values, true)) {
                $values[] = $item;
            }
        }

        return $values;
    }
}
