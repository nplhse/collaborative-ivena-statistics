<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Doctrine;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\ResetPasswordRequest;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The foreign key of reset_password_request deletes the user's open requests with the user (#1398), as the
 * mapping says. Before, the database refused to delete a user with an open request.
 */
final class ResetPasswordRequestUserDeletionTest extends DatabaseKernelTestCase
{
    public function testDeletingAUserDeletesTheirOpenResetRequests(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $connection = self::getContainer()->get(Connection::class);
        $user = UserFactory::createOne(['username' => 'reset-request-owner']);
        $request = new ResetPasswordRequest($user, new \DateTimeImmutable('2026-11-27 12:00:00'), 'selector-1398', 'hashed-token');
        $entityManager->persist($request);
        $entityManager->flush();
        $userId = $user->getId();

        $connection->executeStatement('DELETE FROM "user" WHERE id = :id', ['id' => $userId]);

        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM reset_password_request WHERE user_id = :id', ['id' => $userId]));
    }
}
