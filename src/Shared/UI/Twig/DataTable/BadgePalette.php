<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

use Symfony\Component\Translation\TranslatableMessage;

final class BadgePalette
{
    private const string FALLBACK_BADGE_CLASS = 'bg-secondary text-secondary-fg';

    /**
     * @var array<string, array<int|string, array<string, mixed>>>
     */
    private const array PALETTES = [
        'hospital_location' => [
            'Urban' => ['class' => 'bg-indigo text-indigo-fg', 'label' => 'hospital.badge.location.Urban', 'domain' => 'allocation'],
            'Mixed' => ['class' => 'bg-azure text-azure-fg', 'label' => 'hospital.badge.location.Mixed', 'domain' => 'allocation'],
            'Rural' => ['class' => 'bg-blue text-blue-fg', 'label' => 'hospital.badge.location.Rural', 'domain' => 'allocation'],
        ],
        'hospital_tier' => [
            'Basic' => ['class' => 'bg-purple text-purple-fg', 'label' => 'hospital.badge.tier.Basic', 'domain' => 'allocation'],
            'Extended' => ['class' => 'bg-pink text-pink-fg', 'label' => 'hospital.badge.tier.Extended', 'domain' => 'allocation'],
            'Full' => ['class' => 'bg-red text-red-fg', 'label' => 'hospital.badge.tier.Full', 'domain' => 'allocation'],
        ],
        'hospital_size' => [
            'Small' => ['class' => 'bg-lime text-lime-fg', 'label' => 'hospital.badge.size.Small', 'domain' => 'allocation'],
            'Medium' => ['class' => 'bg-green text-green-fg', 'label' => 'hospital.badge.size.Medium', 'domain' => 'allocation'],
            'Large' => ['class' => 'bg-teal text-teal-fg', 'label' => 'hospital.badge.size.Large', 'domain' => 'allocation'],
        ],
        'allocation_urgency' => [
            '1' => ['class' => 'bg-red text-red-fg', 'label' => 'Emergency Care'],
            '2' => ['class' => 'bg-yellow text-yellow-fg', 'label' => 'Inpatient Care'],
            '3' => ['class' => 'bg-green text-green-fg', 'label' => 'Outpatient Care'],
        ],
        'closure_care_level' => [
            'emergency' => ['class' => 'bg-red-lt text-red'],
            'inpatient' => ['class' => 'bg-yellow-lt text-yellow'],
            'outpatient' => ['class' => 'bg-green-lt text-green'],
            'other' => ['class' => 'bg-secondary-lt text-secondary'],
        ],
        'closure_reason' => [
            'emergency_department_overload' => ['class' => 'bg-secondary-lt text-secondary'],
            'no_bed_capacity' => ['class' => 'bg-gray-lt text-gray'],
            'technical_fault' => ['class' => 'bg-dark-lt text-dark'],
            'operating_room_notice' => ['class' => 'bg-gray-dark-lt text-gray-dark'],
            'not_specified' => ['class' => 'bg-gray-muted-lt text-secondary'],
        ],
        'import_type' => [
            'Allocation' => [
                'presentation' => 'status',
                'statusColor' => 'blue',
                'label' => 'import.type.label.allocation',
                'domain' => 'import',
            ],
            'Closure' => [
                'presentation' => 'status',
                'statusColor' => 'purple',
                'label' => 'import.type.label.closure',
                'domain' => 'import',
            ],
            'Encounter' => [
                'presentation' => 'status',
                'statusColor' => 'teal',
                'label' => 'import.type.label.encounter',
                'domain' => 'import',
            ],
        ],
        'import_status' => [
            'Pending' => [
                'presentation' => 'status',
                'statusColor' => 'dark',
                'icon' => 'tabler:clock',
                'label' => 'import.status.label.pending',
                'domain' => 'import',
            ],
            'Running' => [
                'presentation' => 'status',
                'statusColor' => 'lime',
                'animatedDot' => true,
                'label' => 'import.status.label.running',
                'domain' => 'import',
            ],
            'Completed' => [
                'presentation' => 'status',
                'statusColor' => 'green',
                'icon' => 'tabler:circle-check',
                'label' => 'import.status.label.completed',
                'domain' => 'import',
            ],
            'Failed' => [
                'presentation' => 'status',
                'statusColor' => 'red',
                'icon' => 'tabler:alert-triangle',
                'label' => 'import.status.label.failed',
                'domain' => 'import',
            ],
            'Cancelled' => [
                'presentation' => 'status',
                'statusColor' => 'orange',
                'icon' => 'tabler:circle-x',
                'label' => 'import.status.label.cancelled',
                'domain' => 'import',
            ],
            'Partial' => [
                'presentation' => 'status',
                'statusColor' => 'yellow',
                'icon' => 'tabler:circle-half',
                'label' => 'import.status.label.partial',
                'domain' => 'import',
            ],
        ],
    ];

    public function resolve(?string $palette, mixed $value): BadgeView
    {
        $key = $this->normalizeValue($value);
        $entry = $this->entryFor($palette, $key);
        $label = $this->labelFrom($entry, $key);
        $presentationRaw = $this->stringFrom($entry, 'presentation') ?? '';
        $presentation = BadgePresentation::tryFrom($presentationRaw) ?? BadgePresentation::Badge;

        if (BadgePresentation::Status === $presentation) {
            $statusColor = $this->stringFrom($entry, 'statusColor') ?? 'secondary';

            return new BadgeView(
                label: $label,
                cssClass: 'status status-'.$statusColor,
                presentation: BadgePresentation::Status,
                statusColor: $statusColor,
                icon: $this->stringFrom($entry, 'icon'),
                animatedDot: true === ($entry['animatedDot'] ?? false),
            );
        }

        return new BadgeView(
            label: $label,
            cssClass: $this->stringFrom($entry, 'class') ?? self::FALLBACK_BADGE_CLASS,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function entryFor(?string $palette, string $key): array
    {
        if (null === $palette || !isset(self::PALETTES[$palette][$key])) {
            return [];
        }

        return self::PALETTES[$palette][$key];
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function labelFrom(array $entry, string $key): string|TranslatableMessage
    {
        $label = $this->stringFrom($entry, 'label') ?? $key;
        $domain = $this->stringFrom($entry, 'domain');

        return null === $domain ? $label : new TranslatableMessage($label, domain: $domain);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function stringFrom(array $entry, string $name): ?string
    {
        $value = $entry[$name] ?? null;

        return \is_string($value) ? $value : null;
    }

    private function normalizeValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (\is_string($value)) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return '';
    }
}
