<?php

declare(strict_types=1);

namespace App\Onboarding\Application\Dto;

use App\Onboarding\Domain\Enum\ClinicAccessStatus;

/**
 * Clinic access of a participant for the onboarding card and the dashboard (#1483). $requestUrl points to the current
 * request on "My requests" for a pending or rejected request.
 */
final readonly class ClinicAccessState
{
    public function __construct(
        public ClinicAccessStatus $status,
        public ?string $hospitalLabel = null,
        public ?string $requestUrl = null,
    ) {
    }
}
