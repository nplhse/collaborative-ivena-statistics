<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Command;

use App\Shared\Application\PublicId\PublicIdBackfillExitCode;
use App\Shared\UI\Console\Command\BackfillPublicIdsCommand;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class BackfillPublicIdsCommandTest extends KernelTestCase
{
    use Factories;

    public function testDryRunThenBackfillUserPublicId(): void
    {
        self::bootKernel();
        UserFactory::createOne();

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE "user" SET public_id = NULL');

        $tester = $this->createCommandTester();

        self::assertSame(
            PublicIdBackfillExitCode::SUCCESS,
            $tester->execute(['--dry-run' => true, '--table' => ['user']]),
        );
        self::assertStringContainsString('Dry run finished', $tester->getDisplay());
        self::assertNull($connection->fetchOne('SELECT public_id FROM "user" LIMIT 1'));

        self::assertSame(
            PublicIdBackfillExitCode::SUCCESS,
            $tester->execute(['--table' => ['user']]),
        );
        self::assertStringContainsString('Backfill finished in', $tester->getDisplay());
        self::assertNotNull($connection->fetchOne('SELECT public_id FROM "user" LIMIT 1'));
    }

    public function testInvalidTableReturnsCriticalExitCode(): void
    {
        self::bootKernel();

        $tester = $this->createCommandTester();
        $exitCode = $tester->execute(['--table' => ['unknown_table']]);

        self::assertSame(PublicIdBackfillExitCode::CRITICAL, $exitCode);
        self::assertStringContainsString('Unknown table(s)', $tester->getDisplay());
    }

    private function createCommandTester(): CommandTester
    {
        /** @var BackfillPublicIdsCommand $command */
        $command = self::getContainer()->get(BackfillPublicIdsCommand::class);

        return new CommandTester($command);
    }
}
