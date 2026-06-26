<?php

declare(strict_types=1);

namespace App\Allocation\Application\Indication;

use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Enum\IndicationRawReviewWorklistSegment;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\Allocation\UI\Http\DTO\IndicationRawReviewWorklistQueryDTO;

final readonly class IndicationRawReviewNavigator
{
    public function __construct(
        private IndicationRawRepository $rawRepository,
    ) {
    }

    public function findNext(
        IndicationRawReviewWorklistQueryDTO $context,
        ?int $currentId = null,
    ): ?IndicationRaw {
        $navigationContext = new IndicationRawReviewWorklistQueryDTO(
            page: 1,
            limit: $context->limit,
            orderBy: $context->orderBy,
            sortBy: $context->sortBy,
            search: $context->search,
            segment: $this->navigationSegment($context->segment),
        );

        return $this->rawRepository->findNextInWorklist($navigationContext, $currentId);
    }

    private function navigationSegment(IndicationRawReviewWorklistSegment $segment): IndicationRawReviewWorklistSegment
    {
        return match ($segment) {
            IndicationRawReviewWorklistSegment::New,
            IndicationRawReviewWorklistSegment::TopOpen => IndicationRawReviewWorklistSegment::Open,
            default => $segment,
        };
    }
}
