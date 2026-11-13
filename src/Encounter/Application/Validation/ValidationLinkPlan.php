<?php

declare(strict_types=1);

namespace App\Encounter\Application\Validation;

use App\Encounter\Application\Review\SwapProposal;

/**
 * @psalm-api Read by the validation templates.
 *
 * How a validation verdict would link an allocation and a visit (#1057), see {@see ValidationLinkPlanner}:
 *
 * - `confirm`: confirm the pair (a link the allocation had to another visit is rejected, as a review "change")
 * - `swap`: apply the review's swap proposal of `allocationId`, which links the pair
 * - `linked`: the pair is already confirmed, nothing to link
 * - `refused`: a link of `allocationId` stands in the way and the review offers no swap; decide in its review
 */
final readonly class ValidationLinkPlan
{
    public const string CONFIRM = 'confirm';

    public const string SWAP = 'swap';

    public const string LINKED = 'linked';

    public const string REFUSED = 'refused';

    private function __construct(
        public string $action,
        /** The allocation whose swap applies or whose link blocks; null for confirm and linked. */
        public ?int $allocationId = null,
        public ?SwapProposal $swap = null,
    ) {
    }

    public static function confirm(): self
    {
        return new self(self::CONFIRM);
    }

    public static function linked(): self
    {
        return new self(self::LINKED);
    }

    public static function swap(SwapProposal $swap): self
    {
        return new self(self::SWAP, $swap->allocationId, $swap);
    }

    public static function refused(int $allocationId): self
    {
        return new self(self::REFUSED, $allocationId);
    }

    public function canLink(): bool
    {
        return self::CONFIRM === $this->action || self::SWAP === $this->action;
    }
}
