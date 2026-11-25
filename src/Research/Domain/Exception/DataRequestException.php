<?php

declare(strict_types=1);

namespace App\Research\Domain\Exception;

/**
 * A data extract request refused a change that its state does not allow.
 */
final class DataRequestException extends \DomainException
{
}
