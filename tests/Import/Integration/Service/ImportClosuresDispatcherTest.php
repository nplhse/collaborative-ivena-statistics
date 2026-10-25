<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Exception\DispatchException;
use App\Import\Application\Exception\ImportCreatorMissingException;
use App\Import\Application\Exception\ImportNotFoundException;
use App\Import\Application\Exception\ImportTypeMismatchException;
use App\Import\Application\Service\ImportClosuresDispatcher;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ImportClosuresDispatcherTest extends DatabaseKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testUnknownImportIsRejected(): void
    {
        $this->expectException(ImportNotFoundException::class);

        $this->dispatcher()->dispatch(2_147_483_647, ImportRunTrigger::Console);
    }

    public function testImportWithoutCreatorIsRejected(): void
    {
        $import = $this->closureImport();
        $createdBy = new \ReflectionProperty(Import::class, 'createdBy');
        $createdBy->setValue($import, null);

        $this->expectException(ImportCreatorMissingException::class);
        $this->expectExceptionMessage('has no createdBy user');

        $this->dispatcher()->dispatch((int) $import->getId(), ImportRunTrigger::Console);
    }

    public function testAllocationImportIsRejected(): void
    {
        $import = $this->closureImport();
        $import->setType(ImportType::ALLOCATION);

        $this->expectException(ImportTypeMismatchException::class);
        $this->expectExceptionMessage('app:import:start');

        $this->dispatcher()->dispatch((int) $import->getId(), ImportRunTrigger::Console);
    }

    public function testBusFailureIsReportedAsDispatchException(): void
    {
        $import = $this->closureImport();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus down'));

        try {
            $this->dispatcher($bus)->dispatch((int) $import->getId(), ImportRunTrigger::Console);
            self::fail('Expected a DispatchException.');
        } catch (DispatchException $e) {
            self::assertStringContainsString('bus down', $e->getMessage());
        }

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame([], self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $import->getId()), 'A failed dispatch leaves no queued run behind.');
        self::assertNull($em->find(Import::class, $import->getId())?->getCurrentRunId());
    }

    public function testDispatchRegistersAConsoleRun(): void
    {
        $import = $this->closureImport();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $this->dispatcher($bus)->dispatch((int) $import->getId(), ImportRunTrigger::Console);

        $runs = self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $import->getId());
        self::assertCount(1, $runs);
        self::assertSame(ImportRunTrigger::Console, $runs[0]->getTrigger());
    }

    public function testDispatchRestoresTheLoggedInUsersToken(): void
    {
        $import = $this->closureImport();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $tokenStorage->setToken($token);

        $this->dispatcher($bus)->dispatch((int) $import->getId(), ImportRunTrigger::Reprocess, $admin->getId());

        self::assertSame($token, $tokenStorage->getToken(), 'Clearing the token would log the user out after a reprocess.');
        $runs = self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $import->getId());
        self::assertSame(ImportRunTrigger::Reprocess, $runs[0]->getTrigger());
        self::assertSame($admin->getId(), $runs[0]->getInitiatedBy()?->getId());
    }

    public function testFailedDispatchAlsoRestoresTheToken(): void
    {
        $import = $this->closureImport();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus down'));
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $tokenStorage->setToken($token);

        try {
            $this->dispatcher($bus)->dispatch((int) $import->getId(), ImportRunTrigger::Reprocess, $admin->getId());
            self::fail('Expected a DispatchException.');
        } catch (DispatchException) {
        }

        self::assertSame($token, $tokenStorage->getToken());
    }

    private function dispatcher(?MessageBusInterface $bus = null): ImportClosuresDispatcher
    {
        return new ImportClosuresDispatcher(
            self::getContainer()->get(ImportRepository::class),
            $bus ?? $this->createStub(MessageBusInterface::class),
            self::getContainer()->get(TokenStorageInterface::class),
            self::getContainer()->get(ImportRunLifecycle::class),
        );
    }

    private function closureImport(): Import
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['state' => $state]);
        $hospital = HospitalFactory::createOne([
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);

        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $user,
            'type' => ImportType::CLOSURE,
        ]);
    }
}
