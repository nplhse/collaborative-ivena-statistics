<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Audit;

use App\Shared\Infrastructure\Audit\Entity\AuditEntry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    #[DataProvider('derivedColumnCases')]
    public function testDerivesIntentAndOutcomeOnlyFromStoredValues(
        string $action,
        ?array $metadata,
        ?string $expectedIntent,
        ?string $expectedOutcome,
    ): void {
        self::assertSame($expectedIntent, AuditEntry::intentFrom($metadata));
        self::assertSame($expectedOutcome, AuditEntry::outcomeFrom($action, $metadata));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>|null, ?string, ?string}>
     */
    public static function derivedColumnCases(): iterable
    {
        yield 'update without metadata' => ['update', null, null, null];
        yield 'update with intent' => ['update', ['intent' => 'user.admin.updated'], 'user.admin.updated', null];
        yield 'empty intent is no intent' => ['update', ['intent' => ''], null, null];
        yield 'non-string intent is ignored' => ['update', ['intent' => ['x']], null, null];
        yield 'failed import run' => ['update', ['intent' => 'import.run.failed', 'intent_metadata' => ['reason' => 'x']], 'import.run.failed', 'failed'];
        yield 'finished import run states no outcome' => ['update', ['intent' => 'import.run.finished'], 'import.run.finished', null];
        yield 'successful export' => ['export', ['blocked' => false, 'success' => true], null, 'success'];
        yield 'failed export' => ['export', ['blocked' => false, 'success' => false], null, 'failed'];
        yield 'blocked export wins over success flag' => ['export', ['blocked' => true, 'success' => false], null, 'blocked'];
        yield 'export without flags' => ['export', ['rowCount' => 3], null, null];
        yield 'success flag outside exports is ignored' => ['download', ['intent' => 'import.source_file.downloaded', 'success' => true], 'import.source_file.downloaded', null];
    }
}
