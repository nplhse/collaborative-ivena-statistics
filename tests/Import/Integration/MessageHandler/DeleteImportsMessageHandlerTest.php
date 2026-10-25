<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\MessageHandler;

use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Message\DeleteImportsMessage;
use App\Import\Application\MessageHandler\DeleteImportsMessageHandler;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class DeleteImportsMessageHandlerTest extends DatabaseKernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testDeletesAllowedImportsSkipsTheOthersAndSendsOneSummary(): void
    {
        $owner = $this->participant();
        $hospital = $this->hospital($owner);
        $first = $this->import($owner, $hospital);
        $second = $this->import($owner, $hospital);
        $running = $this->import($owner, $hospital, ImportStatus::RUNNING);
        $foreignOwner = $this->participant();
        $foreign = $this->import($foreignOwner, $this->hospital($foreignOwner));
        $ids = [(int) $first->getId(), (int) $running->getId(), (int) $foreign->getId(), (int) $second->getId(), 2_000_000_000];
        $this->actAs($owner);

        $message = new DeleteImportsMessage('0199e000-0000-7000-8000-000000000001', (int) $owner->getId(), $ids);
        $this->handler()($message);

        self::assertSame(
            [(int) $running->getId(), (int) $foreign->getId()],
            array_map(intval(...), $this->connection()->fetchFirstColumn('SELECT id FROM import WHERE id IN (?) ORDER BY id', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER])),
            'the running and the foreign import stay',
        );

        $row = $this->connection()->fetchAssociative('SELECT type, parameters, action_route, priority FROM notification WHERE recipient_id = ?', [$owner->getId()]);
        self::assertIsArray($row);
        self::assertSame('import.bulk_deleted', $row['type']);
        // The unknown id counts as deleted: it is gone, for example after an earlier attempt of this message.
        self::assertSame(['total' => 5, 'deleted' => 3, 'skipped' => 2, 'failed' => 0], json_decode((string) $row['parameters'], true));
        self::assertSame('app_import_index', $row['action_route']);
        self::assertSame('normal', $row['priority']);
    }

    public function testARetryDoesNotSendASecondSummary(): void
    {
        $owner = $this->participant();
        $import = $this->import($owner, $this->hospital($owner));
        $this->actAs($owner);
        $message = new DeleteImportsMessage('0199e000-0000-7000-8000-000000000002', (int) $owner->getId(), [(int) $import->getId()]);

        $this->handler()($message);
        $this->handler()($message);

        self::assertSame(1, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification WHERE recipient_id = ?', [$owner->getId()]));
    }

    private function handler(): DeleteImportsMessageHandler
    {
        return self::getContainer()->get(DeleteImportsMessageHandler::class);
    }

    /**
     * The audit middleware authenticates the initiator for the message; the test does the same.
     */
    private function actAs(User $user): void
    {
        self::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
    }

    private function participant(): User
    {
        return UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
    }

    private function hospital(User $owner): Hospital
    {
        $state = StateFactory::createOne();

        return HospitalFactory::createOne([
            'owner' => $owner,
            'createdBy' => $owner,
            'state' => $state,
            'dispatchArea' => DispatchAreaFactory::createOne(['state' => $state]),
        ]);
    }

    private function import(User $creator, Hospital $hospital, ImportStatus $status = ImportStatus::COMPLETED): Import
    {
        return ImportFactory::createOne([
            'hospital' => $hospital,
            'createdBy' => $creator,
            'type' => ImportType::ALLOCATION,
            'status' => $status,
            'filePath' => 'var/imports/_tests/never-written.csv',
        ]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
