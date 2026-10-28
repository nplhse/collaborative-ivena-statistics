<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Demo;

use App\DataFixtures\Generator\Application\Dataset\DatasetPlan;

/**
 * The committed master-data part of a wizard run: the participant, the hospital and the checked dataset plan.
 */
final readonly class DemoProvisioning
{
    /**
     * @param list<string> $changes what was changed on an existing hospital
     */
    public function __construct(
        public DemoHospitalRequest $request,
        public DemoParticipant $participant,
        public int $hospitalId,
        public string $hospitalName,
        public string $hospitalPublicId,
        public ?string $ownerName,
        public array $changes,
        public DatasetPlan $plan,
    ) {
    }

    /**
     * @return list<string> months the run writes (Y-m)
     */
    public function months(): array
    {
        return $this->plan->hospitals[0]->generate ?? [];
    }
}
