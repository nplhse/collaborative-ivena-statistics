<?php

declare(strict_types=1);

namespace App\Shared\UI\Twig\DataTable;

final class BadgePalette
{
    private const string FALLBACK_BADGE_CLASS = 'bg-secondary text-secondary-fg';

    /**
     * @var array<string, array<int|string, array<string, mixed>>>
     */
    private const array PALETTES = [
        'hospital_location' => [
            'Urban' => ['class' => 'bg-indigo text-indigo-fg', 'label' => 'Urban'],
            'Mixed' => ['class' => 'bg-azure text-azure-fg', 'label' => 'Mixed'],
            'Rural' => ['class' => 'bg-blue text-blue-fg', 'label' => 'Rural'],
        ],
        'hospital_tier' => [
            'Basic' => ['class' => 'bg-purple text-purple-fg', 'label' => 'Basic'],
            'Extended' => ['class' => 'bg-pink text-pink-fg', 'label' => 'Extended'],
            'Full' => ['class' => 'bg-red text-red-fg', 'label' => 'Full'],
        ],
        'hospital_size' => [
            'Small' => ['class' => 'bg-lime text-lime-fg', 'label' => 'Small'],
            'Medium' => ['class' => 'bg-green text-green-fg', 'label' => 'Medium'],
            'Large' => ['class' => 'bg-teal text-teal-fg', 'label' => 'Large'],
        ],
        'allocation_urgency' => [
            '1' => ['class' => 'bg-red text-red-fg', 'label' => 'Emergency Care'],
            '2' => ['class' => 'bg-yellow text-yellow-fg', 'label' => 'Inpatient Care'],
            '3' => ['class' => 'bg-green text-green-fg', 'label' => 'Outpatient Care'],
        ],
        'import_type' => [
            'Allocation' => [
                'presentation' => 'status',
                'statusColor' => 'blue',
                'label' => 'Allocation',
            ],
        ],
        'import_status' => [
            'Pending' => [
                'presentation' => 'status',
                'statusColor' => 'dark',
                'icon' => 'tabler:clock',
                'label' => 'Pending',
            ],
            'Running' => [
                'presentation' => 'status',
                'statusColor' => 'lime',
                'animatedDot' => true,
                'label' => 'Running',
            ],
            'Completed' => [
                'presentation' => 'status',
                'statusColor' => 'green',
                'icon' => 'tabler:circle-check',
                'label' => 'Completed',
            ],
            'Failed' => [
                'presentation' => 'status',
                'statusColor' => 'red',
                'icon' => 'tabler:alert-triangle',
                'label' => 'Failed',
            ],
            'Cancelled' => [
                'presentation' => 'status',
                'statusColor' => 'orange',
                'icon' => 'tabler:circle-x',
                'label' => 'Cancelled',
            ],
            'Partial' => [
                'presentation' => 'status',
                'statusColor' => 'yellow',
                'icon' => 'tabler:circle-half',
                'label' => 'Partial',
            ],
        ],
    ];

    public function resolve(?string $palette, mixed $value): BadgeView
    {
        $key = $this->normalizeValue($value);
        $entry = $this->entryFor($palette, $key);
        $label = $this->stringFrom($entry, 'label') ?? $key;
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
