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
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class ImportClosuresDispatcherTest extends DatabaseKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testUnknownImportIsRejected(): void
    {
        $this->expectException(ImportNotFoundException::class);

        $this->dispatcher()->dispatch(2_147_483_647);
    }

    public function testImportWithoutCreatorIsRejected(): void
    {
        $import = $this->closureImport();
        $createdBy = new \ReflectionProperty(Import::class, 'createdBy');
        $createdBy->setValue($import, null);

        $this->expectException(ImportCreatorMissingException::class);
        $this->expectExceptionMessage('has no createdBy user');

        $this->dispatcher()->dispatch((int) $import->getId());
    }

    public function testAllocationImportIsRejected(): void
    {
        $import = $this->closureImport();
        $import->setType(ImportType::ALLOCATION);

        $this->expectException(ImportTypeMismatchException::class);
        $this->expectExceptionMessage('app:import:start');

        $this->dispatcher()->dispatch((int) $import->getId());
    }

    public function testBusFailureIsReportedAsDispatchException(): void
    {
        $import = $this->closureImport();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willThrowException(new \RuntimeException('bus down'));

        $this->expectException(DispatchException::class);
        $this->expectExceptionMessage('bus down');

        $this->dispatcher($bus)->dispatch((int) $import->getId());
    }

    private function dispatcher(?MessageBusInterface $bus = null): ImportClosuresDispatcher
    {
        return new ImportClosuresDispatcher(
            self::getContainer()->get(ImportRepository::class),
            $bus ?? $this->createStub(MessageBusInterface::class),
            self::getContainer()->get(TokenStorageInterface::class),
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
