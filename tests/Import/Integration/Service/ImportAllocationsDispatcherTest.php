<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Message\ImportAllocationsMessage;
use App\Import\Application\Service\ImportAllocationsDispatcher;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Import\Infrastructure\Repository\ImportRunRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class ImportAllocationsDispatcherTest extends DatabaseKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testReprocessRunIsQueuedAndTheLoggedInUsersTokenIsRestored(): void
    {
        $import = $this->allocationImport();
        $messages = [];
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$messages): Envelope {
            $messages[] = $message;

            return new Envelope($message);
        });
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $tokenStorage = self::getContainer()->get(TokenStorageInterface::class);
        $token = new UsernamePasswordToken($admin, 'main', $admin->getRoles());
        $tokenStorage->setToken($token);

        $this->dispatcher($bus)->dispatch((int) $import->getId(), ImportRunTrigger::Reprocess, $admin->getId());

        self::assertSame($token, $tokenStorage->getToken(), 'Clearing the token would log the user out after a reprocess.');
        self::assertCount(1, $messages);
        self::assertInstanceOf(ImportAllocationsMessage::class, $messages[0]);
        self::assertSame($admin->getId(), $messages[0]->initiatedByUserId);

        $runs = self::getContainer()->get(ImportRunRepository::class)->findByImportId((int) $import->getId());
        self::assertCount(1, $runs);
        self::assertSame(ImportRunTrigger::Reprocess, $runs[0]->getTrigger());
    }

    private function dispatcher(MessageBusInterface $bus): ImportAllocationsDispatcher
    {
        return new ImportAllocationsDispatcher(
            self::getContainer()->get(ImportRepository::class),
            $bus,
            self::getContainer()->get(TokenStorageInterface::class),
            self::getContainer()->get(ImportRunLifecycle::class),
        );
    }

    private function allocationImport(): Import
    {
        $state = StateFactory::createOne();

        return ImportFactory::createOne([
            'hospital' => HospitalFactory::createOne([
                'state' => $state,
                'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
            ]),
            'createdBy' => UserFactory::createOne(),
            'type' => ImportType::ALLOCATION,
        ]);
    }
}
