<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Audit;

use App\Admin\Application\Audit\AuditDetailValue;
use App\Admin\Application\Audit\AuditEntryDetails;
use PHPUnit\Framework\TestCase;

final class AuditEntryDetailsTest extends TestCase
{
    public function testOldNewChangesBecomeRowsAndMetadataKeepsItsOrder(): void
    {
        $details = AuditEntryDetails::from(
            AuditTimelineFixture::entry(1, '10:00:00', 'r1'),
            ['name' => ['old' => 'A', 'new' => 'B'], 'active' => ['old' => false, 'new' => true]],
            ['intent' => 'user.admin.updated', 'intent_metadata' => ['hospital_id' => 3]],
        );

        self::assertSame(['name', 'active'], array_column($details->changes, 'field'));
        self::assertSame('B', $details->changes[0]['new']->text);
        self::assertSame('bool', $details->changes[1]['old']->kind);
        self::assertSame('false', $details->changes[1]['old']->text);
        self::assertNull($details->rawChanges);
        self::assertSame(['intent', 'intent_metadata'], array_column($details->metadata, 'key'));
        self::assertSame('json', $details->metadata[1]['value']->kind);
        self::assertStringContainsString('"hospital_id": 3', $details->metadata[1]['value']->text);
    }

    public function testChangesOfAnotherShapeAreShownAsJson(): void
    {
        $details = AuditEntryDetails::from(AuditTimelineFixture::entry(1, '10:00:00', 'r1'), ['rows' => 3], null);

        self::assertSame([], $details->changes);
        self::assertNotNull($details->rawChanges);
        self::assertSame('json', $details->rawChanges->kind);
        self::assertSame([], $details->metadata);
    }

    public function testNoChanges(): void
    {
        $details = AuditEntryDetails::from(AuditTimelineFixture::entry(1, '10:00:00', 'r1'), [], null);

        self::assertSame([], $details->changes);
        self::assertNull($details->rawChanges);
    }

    public function testValueKindsAndTruncation(): void
    {
        self::assertSame('null', AuditDetailValue::of(null)->kind);
        self::assertSame('number', AuditDetailValue::of(1.5)->kind);
        self::assertSame('********', AuditDetailValue::of('********')->text, 'masked values stay masked');

        $long = AuditDetailValue::of(str_repeat('ä', AuditDetailValue::MAX_LENGTH + 5));
        self::assertTrue($long->truncated);
        self::assertSame(AuditDetailValue::MAX_LENGTH, mb_strlen($long->text));
        self::assertFalse(AuditDetailValue::of('short')->truncated);
    }
}
