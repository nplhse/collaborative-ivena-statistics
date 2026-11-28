<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Security\LoginFailure;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Entity\LoginFailureDaily;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Query\LoginFailureOverviewQuery;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(LoginFailureOverviewQuery::class)]
final class LoginFailureOverviewQueryTest extends DatabaseKernelTestCase
{
    public function testDailyRowsFromTheGivenDayNewestFirst(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($this->day('2026-10-28'));
        $entityManager->persist($this->day('2026-10-29'));
        $entityManager->persist($this->day('2026-11-27'));
        $entityManager->flush();

        $rows = self::getContainer()->get(LoginFailureOverviewQuery::class)->dailySince(new \DateTimeImmutable('2026-10-29'));

        self::assertSame(['2026-11-27', '2026-10-29'], array_map(static fn (LoginFailureDaily $row): string => $row->getDate()->format('Y-m-d'), $rows));
    }

    public function testOnlyAccountsLockedBeyondNowAreListed(): void
    {
        self::bootKernel();
        $this->lockedUser('expired', '2026-11-28 10:00:00');
        $this->lockedUser('later', '2026-11-28 12:00:00');
        $this->lockedUser('soon', '2026-11-28 10:30:00');
        UserFactory::createOne(['username' => 'never']);

        $accounts = self::getContainer()->get(LoginFailureOverviewQuery::class)->lockedAccounts(new \DateTimeImmutable('2026-11-28 10:00:00'));

        self::assertSame(['soon', 'later'], array_column($accounts, 'username'));
        self::assertEquals(new \DateTimeImmutable('2026-11-28 10:30:00'), $accounts[0]['lockedUntil']);
    }

    private function lockedUser(string $username, string $until): void
    {
        UserFactory::new(['username' => $username])
            ->afterInstantiate(static fn (User $user) => $user->lockLoginUntil(new \DateTimeImmutable($until)))
            ->create();
    }

    private function day(string $date): LoginFailureDaily
    {
        return new LoginFailureDaily(new \DateTimeImmutable($date), 1, 0, 0, 0, 1, 1, 1, 1);
    }
}
