<?php

declare(strict_types=1);

namespace App\Tests\Import\Functional\Command;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Allocation\Infrastructure\Factory\OccasionFactory;
use App\Allocation\Infrastructure\Factory\SecondaryTransportFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\DTO\ImportRequeueBatchOptions;
use App\Import\Application\ImportDispatchExitCode;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportAllocationsDispatcher;
use App\Import\Application\Service\ImportRequeueBatchOrchestrator;
use App\Import\Application\Service\ImportRequeueResumeResolver;
use App\Import\Domain\Entity\ImportBatchRun;
use App\Import\Domain\Entity\ImportBatchRunItem;
use App\Import\Domain\Enum\ImportBatchRunStatus;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportBatchRunRepository;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class RequeueAllImportsCommandTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDryRunDispatchesNothingAndWritesNoCheckpoints(): void
    {
        $seed = $this->seedReferenceGraph();
        ImportFactory::createMany(2, ['hospital' => $seed['hospital'], 'createdBy' => $seed['user']]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(ImportDispatchExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('would_dispatch', $tester->getDisplay());
        self::assertSame(0, $this->countBatchRuns());
    }

    public function testLimitRestrictsProcessedImports(): void
    {
        $seed = $this->seedReferenceGraph();
        ImportFactory::createMany(3, ['hospital' => $seed['hospital'], 'createdBy' => $seed['user']]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--limit' => '2']);

        self::assertSame(ImportDispatchExitCode::SUCCESS, $exitCode);
        self::assertSame(1, $this->countBatchRuns());
        self::assertSame(2, $this->countBatchRunItems());
    }

    public function testFromIdFiltersImports(): void
    {
        $seed = $this->seedReferenceGraph();
        ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Older Import']);
        $newer = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Newer Import']);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--from-id' => (string) $newer->getId(),
            '--limit' => '1',
        ]);

        self::assertSame(ImportDispatchExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('Newer Import', $tester->getDisplay());
        self::assertStringNotContainsString('Older Import', $tester->getDisplay());
    }

    public function testOnlyIdProcessesSingleImport(): void
    {
        $seed = $this->seedReferenceGraph();
        ImportFactory::createMany(3, ['hospital' => $seed['hospital'], 'createdBy' => $seed['user']]);
        $target = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Target Import']);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--only-id' => (string) $target->getId()]);

        self::assertSame(ImportDispatchExitCode::SUCCESS, $exitCode);
        self::assertSame(1, $this->countBatchRunItems());
    }

    public function testResumeAfterRunningRetriesSameImport(): void
    {
        $seed = $this->seedReferenceGraph();
        $first = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'First Import']);
        ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Second Import']);

        $run = new ImportBatchRun([]);
        $item = new ImportBatchRunItem($first->getId(), 'First Import');
        $item->markRunning();
        $run->addItem($item);
        $run->setStatus(ImportBatchRunStatus::Interrupted);
        $this->em->persist($run);
        $this->em->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--resume' => true]);

        self::assertSame(ImportDispatchExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('First Import', $tester->getDisplay());
    }

    public function testResumeAfterQueuedContinuesWithNextImport(): void
    {
        $seed = $this->seedReferenceGraph();
        $first = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Done Import']);
        $second = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Next Import']);

        $run = new ImportBatchRun([]);
        $item = new ImportBatchRunItem($first->getId(), 'Done Import');
        $item->markRunning();
        $item->markQueued();
        $run->addItem($item);
        $run->setStatus(ImportBatchRunStatus::Running);
        $this->em->persist($run);
        $this->em->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--resume' => true]);

        self::assertSame(ImportDispatchExitCode::SUCCESS, $exitCode);
        self::assertStringContainsString('Next Import', $tester->getDisplay());
        self::assertStringNotContainsString('Done Import', $tester->getDisplay());

        $this->em->clear();
        $items = $this->em->getRepository(ImportBatchRunItem::class)->findBy(['importId' => $second->getId()]);
        self::assertNotEmpty($items);
    }

    public function testMaxRetriesReturnsCriticalExit(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user']]);

        $run = new ImportBatchRun([]);
        $item = new ImportBatchRunItem($import->getId(), 'Failed Import');
        $item->markRunning();
        $item->markDispatchFailed('error');
        $item->markRunning();
        $item->markDispatchFailed('error');
        $item->markRunning();
        $item->markDispatchFailed('error');
        $run->addItem($item);
        $run->setStatus(ImportBatchRunStatus::Failed);
        $this->em->persist($run);
        $this->em->flush();

        $tester = $this->commandTester();
        $exitCode = $tester->execute([
            '--resume' => true,
            '--max-retries-per-import' => '3',
        ]);

        self::assertSame(ImportDispatchExitCode::CRITICAL, $exitCode);
        self::assertStringContainsString('Max retries exceeded', $tester->getDisplay());
    }

    public function testDispatchFailureContinuesAndReturnsFailureExit(): void
    {
        $seed = $this->seedReferenceGraph();
        $first = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Fail Import']);
        ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Ok Import']);

        $container = self::getContainer();
        $failImportId = $first->getId();

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($failImportId): Envelope {
            if ($message instanceof ImportAllocationsMessage && $message->importId === $failImportId) {
                throw new \RuntimeException('Simulated dispatch failure');
            }

            return new Envelope($message);
        });

        $dispatcher = new ImportAllocationsDispatcher(
            $container->get(ImportRepository::class),
            $bus,
            $container->get(TokenStorageInterface::class),
        );

        $orchestrator = new ImportRequeueBatchOrchestrator(
            $container->get(ImportRepository::class),
            $container->get(ImportBatchRunRepository::class),
            $dispatcher,
            new ImportRequeueResumeResolver(),
        );

        $summary = $orchestrator->run(new ImportRequeueBatchOptions(limit: 2));

        self::assertSame(ImportDispatchExitCode::FAILURE, $summary->exitCode);
        self::assertSame(1, $summary->failed);
        self::assertSame(1, $summary->dispatched);
    }

    public function testSignalInterruptReturnsCriticalExit(): void
    {
        $seed = $this->seedReferenceGraph();
        ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user'], 'name' => 'Interrupt Import']);

        $container = self::getContainer();
        $runControl = new \App\Import\Application\Service\ImportRequeueRunControl();

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message) use ($runControl): Envelope {
            $runControl->requestStop(15);

            return new Envelope($message);
        });

        $dispatcher = new ImportAllocationsDispatcher(
            $container->get(ImportRepository::class),
            $bus,
            $container->get(TokenStorageInterface::class),
        );

        $orchestrator = new ImportRequeueBatchOrchestrator(
            $container->get(ImportRepository::class),
            $container->get(ImportBatchRunRepository::class),
            $dispatcher,
            new ImportRequeueResumeResolver(),
        );

        $summary = $orchestrator->run(new ImportRequeueBatchOptions(), $runControl);

        self::assertSame(ImportDispatchExitCode::CRITICAL, $summary->exitCode);
        self::assertTrue($summary->interrupted);
    }

    public function testImportAllocationsCommandSuccessAndFailureExitCodes(): void
    {
        $seed = $this->seedReferenceGraph();
        $import = ImportFactory::createOne(['hospital' => $seed['hospital'], 'createdBy' => $seed['user']]);

        $application = new Application(self::$kernel);
        $command = $application->find('app:import:allocations');
        $tester = new CommandTester($command);

        $success = $tester->execute(['importId' => (string) $import->getId()]);
        self::assertSame(ImportDispatchExitCode::SUCCESS, $success);

        $failure = $tester->execute(['importId' => '999999']);
        self::assertSame(ImportDispatchExitCode::FAILURE, $failure);
    }

    /**
     * @return array{user: object, hospital: object}
     */
    private function seedReferenceGraph(): array
    {
        $user = UserFactory::createOne(['username' => 'requeue-'.bin2hex(random_bytes(4))]);
        $state = StateFactory::createOne(['name' => 'RequeueState']);
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'RequeueDispatchArea', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'RequeueHospital',
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        SpecialityFactory::createOne(['name' => 'RequeueSpeciality']);
        DepartmentFactory::createOne(['name' => 'RequeueDepartment']);
        AssignmentFactory::createOne(['name' => 'RequeueAssignment']);
        OccasionFactory::createOne(['name' => 'RequeueOccasion']);
        SecondaryTransportFactory::createOne(['name' => 'RequeueSecondaryTransport']);
        InfectionFactory::createOne(['name' => 'RequeueInfection']);
        IndicationRawFactory::createOne(['name' => 'RequeueRawIndication', 'code' => 800001]);
        IndicationNormalizedFactory::createOne(['name' => 'RequeueNormalizedIndication']);

        return [
            'user' => $user,
            'hospital' => $hospital,
        ];
    }

    private function commandTester(): CommandTester
    {
        $application = new Application(self::$kernel);
        $command = $application->find('app:import:requeue-all');

        return new CommandTester($command);
    }

    private function countBatchRuns(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ImportBatchRun::class, 'r')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countBatchRunItems(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(ImportBatchRunItem::class, 'i')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
