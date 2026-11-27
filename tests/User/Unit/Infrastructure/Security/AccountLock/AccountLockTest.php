<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\AccountLock;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Shared\Infrastructure\Audit\AuditContext;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\AccountLock\AccountLock;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(AccountLock::class)]
final class AccountLockTest extends TestCase
{
    private MockClock $clock;

    /** @var list<NotificationRequest> */
    private array $published = [];

    private int $flushes = 0;

    private AccountLock $accountLock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-27 10:00:00', 'UTC');

        $publisher = $this->createStub(NotificationPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (NotificationRequest $request): void {
            $this->published[] = $request;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        $this->accountLock = new AccountLock(
            new RateLimiterFactory(['id' => 'login_account_failure', 'policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 hour'], new InMemoryStorage()),
            $entityManager,
            new AuditContext(),
            $publisher,
            $this->clock,
            new NullLogger(),
        );
    }

    public function testNineteenWrongPasswordsDoNotLockTheAccount(): void
    {
        $user = $this->user(7);

        $this->failPasswords($user, 19);

        self::assertFalse($this->accountLock->isLocked($user));
        self::assertNull($user->getLoginLockedUntil());
        self::assertSame([], $this->published);
    }

    public function testTheTwentiethWrongPasswordLocksForThreeHours(): void
    {
        $user = $this->user(7);

        $this->failPasswords($user, 20);

        self::assertTrue($this->accountLock->isLocked($user));
        self::assertEquals(new \DateTimeImmutable('2026-11-27 13:00:00', new \DateTimeZone('UTC')), $user->getLoginLockedUntil());
        self::assertSame(1, $this->flushes);
    }

    public function testTheOwnerIsToldOncePerLock(): void
    {
        $user = $this->user(7);

        // Wrong passwords while the account is locked neither extend the lock nor send another notice.
        $this->failPasswords($user, 40);

        self::assertCount(1, $this->published);
        self::assertSame('security.account_locked', $this->published[0]->type);
        self::assertSame([7], $this->published[0]->recipientUserIds);
        self::assertSame(['hours' => 3], $this->published[0]->parameters);
        self::assertEquals(new \DateTimeImmutable('2026-11-27 13:00:00', new \DateTimeZone('UTC')), $user->getLoginLockedUntil());
    }

    public function testTheLockEndsAfterThreeHoursAndCountingStartsAgain(): void
    {
        $user = $this->user(7);
        $this->failPasswords($user, 20);

        $this->clock->modify('2026-11-27 12:59:59 UTC');
        self::assertTrue($this->accountLock->isLocked($user));

        $this->clock->modify('2026-11-27 13:00:00 UTC');
        self::assertFalse($this->accountLock->isLocked($user));

        // Earlier failures were forgotten when the account was locked: one more does not lock it again.
        $this->failPasswords($user, 1);
        self::assertFalse($this->accountLock->isLocked($user));
        self::assertCount(1, $this->published);
    }

    public function testFailuresArePerAccount(): void
    {
        $first = $this->user(7);
        $second = $this->user(8);

        $this->failPasswords($first, 15);
        $this->failPasswords($second, 15);

        self::assertFalse($this->accountLock->isLocked($first));
        self::assertFalse($this->accountLock->isLocked($second));
    }

    public function testClearLiftsTheLockAndForgetsEarlierFailures(): void
    {
        $user = $this->user(7);
        $this->failPasswords($user, 20);

        self::assertTrue($this->accountLock->clear($user));
        self::assertFalse($this->accountLock->isLocked($user));
        self::assertNull($user->getLoginLockedUntil());

        $this->failPasswords($user, 19);
        self::assertFalse($this->accountLock->isLocked($user));
    }

    public function testClearOfAnUnlockedAccountForgetsEarlierFailures(): void
    {
        $user = $this->user(7);
        $this->failPasswords($user, 19);

        self::assertFalse($this->accountLock->clear($user));

        $this->failPasswords($user, 19);
        self::assertFalse($this->accountLock->isLocked($user));
    }

    private function failPasswords(User $user, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $this->accountLock->recordFailedPassword($user);
        }
    }

    private function user(int $id): User
    {
        $user = new User()->setUsername('user-'.$id);
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }
}
