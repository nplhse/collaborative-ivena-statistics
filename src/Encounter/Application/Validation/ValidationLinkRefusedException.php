<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

/**
 * A verdict that should link a pair, refused because a link of `allocationId` stands in the way and the
 * review offers no swap for it (#1057). Nothing has been written; the decision belongs to that
 * allocation's review.
 */
final class ValidationLinkRefusedException extends \RuntimeException
{
    public function __construct(public readonly int $allocationId)
    {
        parent::__construct(sprintf('Validation link refused: allocation %d is linked otherwise.', $allocationId));
    }
}
