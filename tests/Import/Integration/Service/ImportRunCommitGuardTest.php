<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\DTO\ImportRunContext;
use App\Import\Application\Exception\ImportRunSupersededException;
use App\Import\Application\Service\ImportRunCommitGuard;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ImportRunCommitGuardTest extends DatabaseKernelTestCase
{
    private EntityManagerInterface $em;
    private ImportRunLifecycle $lifecycle;
    private ImportRunCommitGuard $guard;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->lifecycle = $container->get(ImportRunLifecycle::class);
        $this->guard = $container->get(ImportRunCommitGuard::class);
    }

    protected function tearDown(): void
    {
        $this->guard->deactivate();
        parent::tearDown();
    }

    public function testCommitsWhileTheRunIsCurrent(): void
    {
        $import = $this->createImport();
        $run = $this->lifecycle->register((int) $import->getId());
        $this->guard->activate(new ImportRunContext((int) $import->getId(), $run->getId()));

        $import->setName('Committed by current run');
        $this->guard->flush($this->em);

        self::assertSame('Committed by current run', $this->storedName((int) $import->getId()));
    }

    public function testRollsBackTheCommitOfASupersededRun(): void
    {
        $import = $this->createImport();
        $importId = (int) $import->getId();
        $old = $this->lifecycle->register($importId);
        $this->guard->activate(new ImportRunContext($importId, $old->getId()));
        $this->lifecycle->register($importId);

        $import->setName('Written by superseded run');

        try {
            $this->guard->flush($this->em);
            self::fail('The commit of a superseded run must be refused.');
        } catch (ImportRunSupersededException) {
        }

        self::assertNotSame('Written by superseded run', $this->storedName($importId));
        self::assertFalse($this->em->getConnection()->isTransactionActive());
    }

    public function testFlushesDirectlyWithoutAnActiveRun(): void
    {
        $import = $this->createImport();

        $import->setName('Legacy flush');
        $this->guard->flush($this->em);

        self::assertSame('Legacy flush', $this->storedName((int) $import->getId()));
    }

    private function storedName(int $importId): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT name FROM import WHERE id = :id', ['id' => $importId]);
    }

    private function createImport(): Import
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne(['state' => $state, 'dispatchArea' => $dispatchArea]);

        $import = ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'runCount' => 0,
        ]);

        $managed = $this->em->find(Import::class, $import->getId());
        self::assertInstanceOf(Import::class, $managed);

        return $managed;
    }
}
