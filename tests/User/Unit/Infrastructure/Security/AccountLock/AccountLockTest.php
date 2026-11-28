<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Infrastructure\Security\AccountLock;

use App\Notification\Application\Contract\NotificationPublisherInterface;
use App\Notification\Application\Publishing\NotificationRequest;
use App\Tests\User\Doubles\InMemoryAccountLockWriter;
use App\Tests\User\Doubles\InMemoryLoginFailureRecorder;
use App\User\Domain\Entity\User;
use App\User\Infrastructure\Security\AccountLock\AccountLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

#[CoversClass(AccountLock::class)]
final class AccountLockTest extends TestCase
{
    private MockClock $clock;

    private InMemoryAccountLockWriter $writer;

    private InMemoryLoginFailureRecorder $recorder;

    /** @var list<NotificationRequest> */
    private array $published = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-27 10:00:00', 'UTC');
        $this->writer = new InMemoryAccountLockWriter();
        $this->recorder = new InMemoryLoginFailureRecorder();
    }

    public function testNineteenWrongPasswordsDoNotLockTheAccount(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);

        $this->failPasswords($accountLock, $user, 19);

        self::assertNull($user->getLoginLockedUntil());
    }

    public function testTheTwentiethWrongPasswordLocksForThreeHours(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);
        $this->failPasswords($accountLock, $user, 19);

        $accountLock->recordFailedPassword($user);

        self::assertEquals([7 => new \DateTimeImmutable('2026-11-27 13:00:00', new \DateTimeZone('UTC'))], $this->writer->savedLocks);
        self::assertSame(['account_locked'], $this->recorder->kinds());
    }

    public function testTheOwnerIsToldOnceWithTheLockDuration(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);

        $this->failPasswords($accountLock, $user, 20);

        self::assertCount(1, $this->published);
        self::assertSame('security.account_locked', $this->published[0]->type);
        self::assertSame([7], $this->published[0]->recipientUserIds);
        self::assertSame(['hours' => 3], $this->published[0]->parameters);
    }

    public function testWrongPasswordsDuringALockNeitherExtendItNorTellTheOwnerAgain(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);
        $this->failPasswords($accountLock, $user, 20);

        $this->failPasswords($accountLock, $user, 20);

        self::assertSame(1, $this->writer->saves);
        self::assertCount(1, $this->published);
    }

    public function testTheLockEndsAfterThreeHours(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);
        $this->failPasswords($accountLock, $user, 20);

        $this->clock->modify('2026-11-27 13:00:00 UTC');

        self::assertFalse($accountLock->isLocked($user));
    }

    public function testTheLockHoldsUntilItsLastSecond(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);
        $this->failPasswords($accountLock, $user, 20);

        $this->clock->modify('2026-11-27 12:59:59 UTC');

        self::assertTrue($accountLock->isLocked($user));
    }

    public function testCountingStartsAgainAfterALock(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);
        $this->failPasswords($accountLock, $user, 20);
        $this->clock->modify('2026-11-27 13:00:00 UTC');

        $this->failPasswords($accountLock, $user, 19);

        self::assertSame(1, $this->writer->saves);
    }

    public function testFailuresArePerAccount(): void
    {
        $accountLock = $this->accountLock();
        $this->failPasswords($accountLock, $this->user(7), 15);

        $this->failPasswords($accountLock, $this->user(8), 15);

        self::assertSame(0, $this->writer->saves);
    }

    public function testClearLiftsTheLock(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);
        $this->failPasswords($accountLock, $user, 20);

        $wasLocked = $accountLock->clear($user);

        self::assertTrue($wasLocked);
        self::assertNull($user->getLoginLockedUntil());
    }

    public function testClearForgetsEarlierFailures(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);
        $this->failPasswords($accountLock, $user, 19);
        $accountLock->clear($user);

        $this->failPasswords($accountLock, $user, 19);

        self::assertSame(0, $this->writer->saves);
    }

    public function testClearOfAnUnlockedAccountSaysItWasNotLocked(): void
    {
        $accountLock = $this->accountLock();
        $user = $this->user(7);

        $wasLocked = $accountLock->clear($user);

        self::assertFalse($wasLocked);
    }

    public function testWhenNotEnforcedReachingTheThresholdIsOnlyLoggedAndRecorded(): void
    {
        $accountLock = $this->accountLock(enforced: false);
        $user = $this->user(7);

        // Counting starts again after each threshold, so 40 wrong passwords reach it twice.
        $this->failPasswords($accountLock, $user, 40);

        self::assertNull($user->getLoginLockedUntil());
        self::assertSame(0, $this->writer->saves);
        self::assertSame([], $this->published);
        self::assertSame(['security.login.account_lock_threshold_reached', 'security.login.account_lock_threshold_reached'], $this->logged);
        self::assertSame(['lock_threshold', 'lock_threshold'], $this->recorder->kinds());
    }

    public function testWhenNotEnforcedAnEarlierLockIsIgnored(): void
    {
        $user = $this->user(7);
        $user->lockLoginUntil(new \DateTimeImmutable('2026-11-27 12:00:00', new \DateTimeZone('UTC')));

        $isLocked = $this->accountLock(enforced: false)->isLocked($user);

        self::assertFalse($isLocked);
    }

    private function accountLock(bool $enforced = true): AccountLock
    {
        $publisher = $this->createStub(NotificationPublisherInterface::class);
        $publisher->method('publish')->willReturnCallback(function (NotificationRequest $request): void {
            $this->published[] = $request;
        });

        $logger = new class(function (string $message): void {
            $this->logged[] = $message;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $record */
            public function __construct(private readonly \Closure $record)
            {
            }

            #[\Override]
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                ($this->record)((string) $message);
            }
        };

        return new AccountLock(
            new RateLimiterFactory(['id' => 'login_account_failure', 'policy' => 'sliding_window', 'limit' => 20, 'interval' => '1 hour'], new InMemoryStorage()),
            $this->writer,
            $publisher,
            $this->clock,
            $logger,
            $this->recorder,
            $enforced,
        );
    }

    private function failPasswords(AccountLock $accountLock, User $user, int $times): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $accountLock->recordFailedPassword($user);
        }
    }

    private function user(int $id): User
    {
        $user = new User()->setUsername('user-'.$id);
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }
}
