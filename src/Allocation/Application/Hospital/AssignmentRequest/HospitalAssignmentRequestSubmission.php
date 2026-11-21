<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\AssignmentRequest;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAssignmentRequest;
use App\Allocation\Domain\Enum\HospitalAssignmentReason;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What a participant enters to request a hospital assignment (#975): an existing hospital or, when they could not
 * find it, its name.
 */
final class HospitalAssignmentRequestSubmission
{
    public ?Hospital $hospital = null;

    #[Assert\Length(max: HospitalAssignmentRequest::MAX_NAME_LENGTH)]
    public ?string $hospitalName = null;

    #[Assert\NotNull(message: 'hospital_assignment_request.reason.required')]
    public ?HospitalAssignmentReason $reason = null;

    #[Assert\Length(max: HospitalAssignmentRequest::MAX_TEXT_LENGTH)]
    public ?string $explanation = null;

    #[Assert\Length(max: HospitalAssignmentRequest::MAX_TEXT_LENGTH)]
    public ?string $comment = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if (!$this->hospital instanceof Hospital && '' === trim((string) $this->hospitalName)) {
            $context->buildViolation('hospital_assignment_request.hospital.required')
                ->atPath('hospitalName')
                ->addViolation();
        }

        if ($this->reason instanceof HospitalAssignmentReason && $this->reason->requiresExplanation() && '' === trim((string) $this->explanation)) {
            $context->buildViolation('hospital_assignment_request.explanation.required')
                ->atPath('explanation')
                ->addViolation();
        }
    }
}
