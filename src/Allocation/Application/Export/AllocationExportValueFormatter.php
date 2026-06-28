<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Domain\Enum\AllocationGender;
use App\Allocation\Domain\Enum\AllocationTransportType;
use App\Allocation\Domain\Enum\AllocationUrgency;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class AllocationExportValueFormatter
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function gender(mixed $value, ?string $locale = null): ?string
    {
        $enum = $this->resolveGender($value);
        if (!$enum instanceof AllocationGender) {
            return null;
        }

        return $this->translator->trans($enum->label(), [], null, $locale);
    }

    public function urgency(mixed $value): ?string
    {
        $enum = $this->resolveUrgency($value);

        return $enum instanceof AllocationUrgency ? $enum->skLabel() : null;
    }

    public function transportType(mixed $value, ?string $locale = null): ?string
    {
        $enum = $this->resolveTransportType($value);
        if (!$enum instanceof AllocationTransportType) {
            return null;
        }

        return $this->translator->trans($enum->label(), [], null, $locale);
    }

    private function resolveGender(mixed $value): ?AllocationGender
    {
        if ($value instanceof AllocationGender) {
            return $value;
        }

        if (\is_string($value) && '' !== $value) {
            return AllocationGender::tryFrom($value);
        }

        return null;
    }

    private function resolveUrgency(mixed $value): ?AllocationUrgency
    {
        if ($value instanceof AllocationUrgency) {
            return $value;
        }

        return AllocationUrgency::tryFromQueryValue($value);
    }

    private function resolveTransportType(mixed $value): ?AllocationTransportType
    {
        if ($value instanceof AllocationTransportType) {
            return $value;
        }

        if (\is_string($value) && '' !== $value) {
            return AllocationTransportType::tryFrom($value);
        }

        return null;
    }
}
