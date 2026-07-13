<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application;

use App\User\Application\UserSettingsUpdater;
use App\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class UserSettingsUpdaterTest extends TestCase
{
    public function testUpdateEmailPersistsAndResetsVerification(): void
    {
        $user = new User();
        $user->setEmail('old@example.com');
        $user->setIsVerified(true);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $updater = new UserSettingsUpdater($entityManager);
        $updater->updateEmail($user, 'new@example.com');

        self::assertSame('new@example.com', $user->getEmail());
        self::assertFalse($user->isVerified());
    }
}
