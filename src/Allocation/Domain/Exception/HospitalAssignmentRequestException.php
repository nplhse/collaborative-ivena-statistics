<?php

declare(strict_types=1);

namespace App\Allocation\Domain\Exception;

/**
 * A hospital assignment request cannot take this step, for example because it was decided already (#975).
 */
final class HospitalAssignmentRequestException extends \DomainException
{
}
