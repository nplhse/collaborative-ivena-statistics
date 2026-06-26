<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Entity;

use App\Allocation\Domain\Entity\IndicationNormalized;
use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class IndicationRawTest extends TestCase
{
    public function testSetTargetSyncsNormalized(): void
    {
        $raw = new IndicationRaw();
        $normalized = new IndicationNormalized()->setCode(100)->setName('Norm');

        $raw->setTarget($normalized);

        self::assertSame($normalized, $raw->getTarget());
        self::assertSame($normalized, $raw->getNormalized());
    }

    public function testSetTargetNullClearsNormalized(): void
    {
        $raw = new IndicationRaw();
        $normalized = new IndicationNormalized()->setCode(101)->setName('Norm');
        $raw->setTarget($normalized);

        $raw->setTarget(null);

        self::assertNull($raw->getTarget());
        self::assertNull($raw->getNormalized());
    }

    public function testClearMatchAssignmentClearsAllMatchFields(): void
    {
        $raw = new IndicationRaw();
        $normalized = new IndicationNormalized()->setCode(102)->setName('Norm');
        $matcher = new User();

        $raw->setTarget($normalized);
        $raw->setFirstMatchedBy($matcher);
        $raw->setFirstMatchedAt(new \DateTimeImmutable());

        $raw->clearMatchAssignment();

        self::assertNull($raw->getTarget());
        self::assertNull($raw->getNormalized());
        self::assertNull($raw->getFirstMatchedBy());
        self::assertNull($raw->getFirstMatchedAt());
    }

    public function testReviewAuditFieldsRoundTrip(): void
    {
        $raw = new IndicationRaw();
        $reviewer = new User();
        $reviewedAt = new \DateTimeImmutable('2026-01-15 10:00:00');

        $raw->setReviewStatus(IndicationRawReviewStatus::Matched);
        $raw->setReviewComment('Looks good');
        $raw->setReviewedBy($reviewer);
        $raw->setReviewedAt($reviewedAt);

        self::assertSame(IndicationRawReviewStatus::Matched, $raw->getReviewStatus());
        self::assertSame('Looks good', $raw->getReviewComment());
        self::assertSame($reviewer, $raw->getReviewedBy());
        self::assertSame($reviewedAt, $raw->getReviewedAt());
    }
}
