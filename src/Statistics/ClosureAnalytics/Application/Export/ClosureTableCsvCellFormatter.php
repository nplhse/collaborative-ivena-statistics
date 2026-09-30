<?php

declare(strict_types=1);

namespace App\Statistics\ClosureAnalytics\Application\Export;

use App\Shared\UI\Twig\DataTable\DataTableColumn;
use App\Shared\UI\Twig\DataTable\DataTableColumnType;
use App\Shared\UI\Twig\DataTable\DataTableValueResolver;
use App\Statistics\ClosureAnalytics\Application\ClosureDurationFormatter;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventRow;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureEventType;
use App\Statistics\ClosureAnalytics\Application\DTO\ClosureIntervalTableRow;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ClosureTableCsvCellFormatter
{
    public function __construct(
        private TranslatorInterface $translator,
        private DataTableValueResolver $valueResolver,
    ) {
    }

    public function format(ClosureEventRow|ClosureIntervalTableRow $row, DataTableColumn $column): string|int|null
    {
        if ($row instanceof ClosureEventRow) {
            return $this->formatEvent($row, $column);
        }

        return $this->formatInterval($row, $column);
    }

    private function formatEvent(ClosureEventRow $row, DataTableColumn $column): string|int|null
    {
        return match ($column->key) {
            'startsAt' => $this->dateTime($row->startsAt, $column),
            'endsAt' => $this->dateTime($row->endsAt, $column),
            'hospital' => $row->hospitalName,
            'event' => $this->eventLabel($row->type, $row->sourceGroupId),
            'specialities' => $this->join($row->getSpecialities()),
            'departments' => $this->join($row->getDepartments()),
            'closureCount' => $row->closureCount,
            'careLevels' => $this->join(array_map($this->careLevelLabel(...), $row->getCareLevels())),
            'summedMinutes' => ClosureDurationFormatter::humanize($row->summedMinutes),
            'actualMinutes' => ClosureDurationFormatter::humanize($row->actualMinutes),
            'reasons' => $this->join(array_map($this->reasonLabel(...), $row->getReasons())),
            'closureUnits' => $this->join($row->getClosureUnits()),
            default => $this->fallback($row, $column),
        };
    }

    private function formatInterval(ClosureIntervalTableRow $row, DataTableColumn $column): string|int|null
    {
        return match ($column->key) {
            'startsAt' => $this->dateTime($row->startsAt, $column),
            'endsAt' => $this->dateTime($row->endsAt, $column),
            'hospital' => $row->hospitalName,
            'event' => $this->eventLabel($row->eventType, $row->sourceGroupId),
            'speciality' => $row->specialityName,
            'department' => $row->departmentName,
            'careLevel' => $this->careLevelLabel($row->careLevel),
            'durationMinutes' => ClosureDurationFormatter::humanize($row->durationMinutes),
            'reason' => $this->reasonLabel($row->reason),
            'closureUnit' => $row->closureUnit,
            default => $this->fallback($row, $column),
        };
    }

    private function eventLabel(ClosureEventType $type, ?string $sourceGroupId): string
    {
        $label = $this->translator->trans('stats.closure.events.'.$type->value, domain: 'statistics');
        if (null === $sourceGroupId || '' === $sourceGroupId) {
            return $label;
        }

        return $label.'; '.$sourceGroupId;
    }

    private function careLevelLabel(string $careLevel): string
    {
        $urgencyKey = 'label.urgency.'.$careLevel;
        $urgencyLabel = $this->translator->trans($urgencyKey, domain: 'messages');
        if ($urgencyLabel !== $urgencyKey) {
            return $urgencyLabel;
        }

        return $this->translator->trans('stats.closure.care_level.'.$careLevel, domain: 'statistics');
    }

    private function reasonLabel(string $reason): string
    {
        return $this->translator->trans('stats.closure.reason.'.$reason, domain: 'statistics');
    }

    /**
     * @param list<string> $values
     */
    private function join(array $values): ?string
    {
        return [] === $values ? null : implode('; ', $values);
    }

    private function dateTime(\DateTimeImmutable $value, DataTableColumn $column): string
    {
        $format = $column->option('format', 'd.m.Y H:i');
        $timezone = $column->option('timezone', 'Europe/Berlin');
        $format = \is_string($format) && '' !== $format ? $format : 'd.m.Y H:i';
        $timezone = \is_string($timezone) && '' !== $timezone ? $timezone : 'Europe/Berlin';

        return $value->setTimezone(new \DateTimeZone($timezone))->format($format);
    }

    private function fallback(object $row, DataTableColumn $column): string|int|null
    {
        $value = $this->valueResolver->resolve($row, $column);
        if ($value instanceof \DateTimeImmutable) {
            return $this->dateTime($value, $column);
        }
        if (\is_int($value)) {
            return DataTableColumnType::Number === $column->type ? $value : ClosureDurationFormatter::humanize($value);
        }

        $scalar = $this->valueResolver->scalar($value);

        return null === $scalar || '' === $scalar ? null : $scalar;
    }
}
