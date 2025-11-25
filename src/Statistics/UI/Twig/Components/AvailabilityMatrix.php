<?php

declare(strict_types=1);

namespace App\Statistics\UI\Twig\Components;

use App\Statistics\Infrastructure\Availability\StatsAvailabilityService;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent(name: 'AvailabilityMatrix', template: '@Statistics/components/AvailabilityMatrix.html.twig')]
final class AvailabilityMatrix
{
    /** @psalm-suppress PropertyNotSetInConstructor */
    public string $scopeType;

    /** @psalm-suppress PropertyNotSetInConstructor */
    public string $scopeId;

    public function __construct(
        private readonly StatsAvailabilityService $availabilityService,
    ) {
    }

    /**
     * @return array{
     *   years: list<int>,
     *   months: list<int>,
     *   hasYear: array<non-empty-string, true>,
     *   hasMonth: array<non-empty-string, true>
     * }
     */
    public function data(): array
    {
        return $this->availabilityService->buildMatrix($this->scopeType, $this->scopeId);
    }
}
