<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reset;

use App\Allocation\Application\ReferenceCatalog\Restore\CatalogMaintenanceGuard;
use App\Allocation\Application\ReferenceCatalog\Restore\ReferenceCatalogDependencyInspector;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\State;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\DataFixtures\Reset\Command\ResetOperationalDataCommand;
use App\DataFixtures\Reset\OperationalDataReset;
use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Notification\Infrastructure\Factory\NotificationFactory;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ResetOperationalDataCommandTest extends KernelTestCase
{
    use Factories;

    public function testRemovesEverythingThatBlocksARestoreAndKeepsUsersAndCatalog(): void
    {
        $tester = $this->tester();
        $this->operationalData();
        self::assertNotSame([], $this->inspector()->blockers());

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame([], $this->inspector()->blockers(), 'a restore can run after the reset');
        self::assertSame(1, $this->em()->getRepository(Hospital::class)->count([]));
        self::assertSame(1, $this->em()->getRepository(State::class)->count([]));
        self::assertGreaterThan(0, $this->em()->getRepository(User::class)->count([]));
        self::assertStringContainsString('A restore can run now', $this->flat($tester->getDisplay()));
    }

    public function testDryRunDeletesNothing(): void
    {
        $tester = $this->tester();
        $this->operationalData();

        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('import operational data delete 1', $this->flat($tester->getDisplay()));
        self::assertSame(1, $this->em()->getRepository(Import::class)->count([]));
    }

    public function testNothingIsDeletedWithoutConfirmation(): void
    {
        $tester = $this->tester();
        $this->operationalData();
        $tester->setInputs(['no']);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(1, $this->em()->getRepository(Import::class)->count([]));
    }

    public function testRefusesWhileMessagesAreWaiting(): void
    {
        $tester = $this->tester();
        $this->operationalData();
        $this->connection()->executeStatement("INSERT INTO messenger_messages (body, headers, queue_name, created_at, available_at) VALUES ('{}', '{}', 'high', NOW(), NOW())");

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Stop the workers', $this->flat($tester->getDisplay()));
        self::assertSame(1, $this->em()->getRepository(Import::class)->count([]));
    }

    public function testRefusesWhileARestoreRuns(): void
    {
        $tester = $this->tester();
        /** @var CatalogMaintenanceGuard $guard */
        $guard = self::getContainer()->get(CatalogMaintenanceGuard::class);
        $lock = $guard->acquire();
        self::assertNotNull($lock);

        try {
            $exitCode = $tester->execute(['--force' => true]);
        } finally {
            $lock->release();
        }

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Another restore or development reset is running', $this->flat($tester->getDisplay()));
    }

    public function testRefusesOutsideDevelopmentTestAndDemo(): void
    {
        $application = new Application(self::bootKernel());
        /** @var OperationalDataReset $reset */
        $reset = self::getContainer()->get(OperationalDataReset::class);
        /** @var CatalogMaintenanceGuard $guard */
        $guard = self::getContainer()->get(CatalogMaintenanceGuard::class);
        $command = $application->addCommand(new ResetOperationalDataCommand($reset, $guard, 'prod'));
        self::assertInstanceOf(Command::class, $command);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('only runs in dev, test, demo, not in "prod"', $this->flat($tester->getDisplay()));
    }

    private function operationalData(): void
    {
        $owner = UserFactory::createOne();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $hospital = HospitalFactory::createOne([
            'owner' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
        HospitalAccessGrantFactory::createOne(['hospital' => $hospital, 'user' => $owner, 'createdBy' => $owner]);
        ImportFactory::createOne(['hospital' => $hospital, 'createdBy' => $owner]);
        NotificationFactory::createOne(['recipient' => $owner, 'scopeReferenceId' => $hospital->getId()]);
    }

    private function flat(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }

    private function inspector(): ReferenceCatalogDependencyInspector
    {
        /** @var ReferenceCatalogDependencyInspector $inspector */
        $inspector = self::getContainer()->get(ReferenceCatalogDependencyInspector::class);

        return $inspector;
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return $em;
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:dev:reset-operational-data'));
    }
}
