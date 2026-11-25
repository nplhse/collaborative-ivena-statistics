<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Domain\Enum;

use App\Research\Domain\Enum\DataRequestStatus;
use PHPUnit\Framework\TestCase;

/**
 * Which states are final or editable, and how they are shown (ADR 045).
 */
final class DataRequestStatusTest extends TestCase
{
    public function testOnlyRejectedExpiredAndWithdrawnAreFinal(): void
    {
        $final = array_values(array_filter(DataRequestStatus::cases(), static fn (DataRequestStatus $status): bool => $status->isFinal()));

        self::assertSame([DataRequestStatus::Rejected, DataRequestStatus::Expired, DataRequestStatus::Withdrawn], $final);
    }

    public function testTheApplicantMayEditDraftsAndRequestsHandedBack(): void
    {
        $editable = array_values(array_filter(DataRequestStatus::cases(), static fn (DataRequestStatus $status): bool => $status->isEditable()));

        self::assertSame([DataRequestStatus::Draft, DataRequestStatus::Returned, DataRequestStatus::RevisionRequested], $editable);
    }

    public function testEveryStatusHasALabelAndABadgeColour(): void
    {
        $variants = [];
        foreach (DataRequestStatus::cases() as $status) {
            self::assertSame('data_request.status.'.$status->value, $status->labelKey());
            $variants[$status->value] = $status->badgeVariant();
        }

        self::assertSame([
            'draft' => 'secondary',
            'submitted' => 'azure',
            'returned' => 'azure',
            'in_review' => 'blue',
            'revision_requested' => 'yellow',
            'stalled' => 'yellow',
            'approved' => 'green',
            'rejected' => 'red',
            'expired' => 'secondary',
            'withdrawn' => 'secondary',
        ], $variants);
    }
}
