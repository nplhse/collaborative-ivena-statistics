<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class RawIndicationReview
{
    public function __construct(
        #[Assert\Choice(callback: [self::class, 'statuses'])]
        public string $status,
        public ?string $comment = null,
        #[SerializedName('reviewed_at')]
        #[Assert\DateTime(format: \DateTimeInterface::ATOM)]
        public ?string $reviewedAt = null,
    ) {
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Choice constraint callback
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return array_map(static fn (IndicationRawReviewStatus $case): string => $case->value, IndicationRawReviewStatus::cases());
    }
}
