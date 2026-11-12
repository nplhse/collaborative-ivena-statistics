<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Command;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class BackfillAuditDerivedColumnsCommandTest extends KernelTestCase
{
    public function testPreviewWritesNothingAndApplyCopiesValues(): void
    {
        self::bootKernel();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO audit_log (occurred_at, request_id, origin, action, entity_class, entity_id, changes, metadata)
             VALUES ('2026-06-01 10:00:00', 'r1', 'messenger', 'update', 'App\\Example', '1', '{}', '{\"intent\":\"import.run.failed\"}'),
                    ('2026-06-01 10:00:01', 'r2', 'http', 'export', 'data_export', 'k', '[]', '{\"blocked\":false,\"success\":true}')",
        );

        $tester = new CommandTester(new Application(self::$kernel)->find('app:audit:backfill-derived'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Preview', $tester->getDisplay());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM audit_log WHERE intent IS NOT NULL OR outcome IS NOT NULL'));

        self::assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
        self::assertStringContainsString('Backfill finished', $tester->getDisplay());
        self::assertSame(
            [['intent' => 'import.run.failed', 'outcome' => 'failed'], ['intent' => null, 'outcome' => 'success']],
            $connection->fetchAllAssociative('SELECT intent, outcome FROM audit_log ORDER BY id'),
        );
    }
}
