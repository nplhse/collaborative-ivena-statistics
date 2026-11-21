<?php

declare(strict_types=1);

namespace App\Allocation\Application\Hospital\AssignmentRequest;

/**
 * A hospital assignment request cannot be submitted, withdrawn or decided (#975). The reason is a translation key
 * in the `allocation` domain, so the page can say why.
 */
final class HospitalAssignmentRequestRefused extends \RuntimeException
{
    public const string ALREADY_ASSIGNED = 'hospital_assignment_request.refused.already_assigned';
    public const string ALREADY_PENDING = 'hospital_assignment_request.refused.already_pending';
    public const string NOT_OWN = 'hospital_assignment_request.refused.not_own';
    public const string NOT_PENDING = 'hospital_assignment_request.refused.not_pending';

    public function __construct(
        public readonly string $reason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
