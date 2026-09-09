<?php

declare(strict_types=1);

namespace App\Allocation\UI\Console\Input;

use App\Allocation\Application\Hospital\HospitalGeoScope;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Validator\Constraints as Assert;

final class HospitalGeoCommandInput
{
    #[Option(description: 'Process a single hospital by Doctrine ID', name: 'hospital-id')]
    #[Assert\Positive]
    public ?int $hospitalId = null;

    #[Option(description: 'Process all hospitals in a dispatch area by Doctrine ID', name: 'dispatch-area-id')]
    #[Assert\Positive]
    public ?int $dispatchAreaId = null;

    #[Option(description: 'Process all hospitals in a federal state by Doctrine ID', name: 'state-id')]
    #[Assert\Positive]
    public ?int $stateId = null;

    #[Option(description: 'For dispatch-area or state scope: only participating hospitals', name: 'participating-only')]
    public bool $participatingOnly = false;

    #[Option(description: 'Preview hospitals without calling OpenRouteService (default)', name: 'dry-run')]
    public bool $dryRun = false;

    #[Option(description: 'Call OpenRouteService and persist results', name: 'apply')]
    public bool $apply = false;

    #[Option(description: 'Overwrite existing geographic data', name: 'force')]
    public bool $force = false;

    #[Option(description: 'Pause between OpenRouteService requests in milliseconds (default: 3500)', name: 'delay-ms')]
    public int $delayMs = 3500;

    public function scopeError(): ?string
    {
        $selected = (null !== $this->hospitalId ? 1 : 0)
            + (null !== $this->dispatchAreaId ? 1 : 0)
            + (null !== $this->stateId ? 1 : 0);

        if (1 === $selected) {
            return null;
        }

        return 'Specify exactly one of --hospital-id, --dispatch-area-id, or --state-id.';
    }

    public function toScope(): HospitalGeoScope
    {
        if (null !== $this->hospitalId) {
            return HospitalGeoScope::hospital($this->hospitalId, $this->participatingOnly);
        }

        if (null !== $this->dispatchAreaId) {
            return HospitalGeoScope::dispatchArea($this->dispatchAreaId, $this->participatingOnly);
        }

        if (null !== $this->stateId) {
            return HospitalGeoScope::state($this->stateId, $this->participatingOnly);
        }

        throw new \LogicException('Hospital geo scope options were not validated before toScope().');
    }

    public function ignoresParticipatingOnly(): bool
    {
        return $this->participatingOnly && null !== $this->hospitalId;
    }
}
