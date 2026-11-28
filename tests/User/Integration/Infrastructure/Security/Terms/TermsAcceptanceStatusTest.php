<?php

declare(strict_types=1);

namespace App\Tests\User\Integration\Infrastructure\Security\Terms;

use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Application\Terms\TermsVersion;
use App\User\Domain\Entity\TermsAcceptance;
use App\User\Domain\Entity\User;
use App\User\Domain\Enum\TermsAcceptanceKind;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\TermsAcceptanceRepository;
use App\User\Infrastructure\Security\Terms\TermsAcceptanceStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Whether an account still has to accept the configured terms version (#1564, ADR 043), and the session marker that
 * saves the database query: it holds account and version, so a raised version or another account asks again. The
 * redirect at login is in `TermsAcceptanceAtLoginTest`; which requests the listener asks in
 * `RequireTermsAcceptanceListenerTest`.
 */
final class TermsAcceptanceStatusTest extends DatabaseKernelTestCase
{
    public function testWithoutAConfiguredVersionNobodyIsAsked(): void
    {
        $status = $this->statusFor(null);

        self::assertFalse($status->isRequired());
        self::assertFalse($status->isOutstanding($this->user('alice'), new Session(new MockArraySessionStorage())));
    }

    public function testAnAccountWithoutAcceptanceOfTheVersionIsAsked(): void
    {
        $alice = $this->user('alice');
        $this->accept($alice, '2026-11');

        $outstanding = $this->statusFor('2026-12')->isOutstanding($alice, new Session(new MockArraySessionStorage()));

        self::assertTrue($outstanding);
    }

    public function testAnAcceptanceOfTheVersionLetsTheAccountThrough(): void
    {
        $alice = $this->user('alice');
        $this->accept($alice, '2026-12');

        $outstanding = $this->statusFor('2026-12')->isOutstanding($alice, null);

        self::assertFalse($outstanding);
    }

    public function testAnAcceptanceFoundInTheDatabaseIsRememberedInTheSession(): void
    {
        $alice = $this->user('alice');
        $this->accept($alice, '2026-12');
        $session = new Session(new MockArraySessionStorage());
        $this->statusFor('2026-12')->isOutstanding($alice, $session);
        // Without the acceptance row only the session marker can let Alice through.
        $this->removeAcceptances();

        $outstanding = $this->statusFor('2026-12')->isOutstanding($alice, $session);

        self::assertFalse($outstanding);
    }

    public function testARememberedAcceptanceCountsForThatVersionOnly(): void
    {
        $alice = $this->user('alice');
        $session = new Session(new MockArraySessionStorage());
        $this->statusFor('2026-12')->remember($alice, $session);

        // A deploy with a raised version, the same session.
        $outstanding = $this->statusFor('2027-03')->isOutstanding($alice, $session);

        self::assertTrue($outstanding);
    }

    public function testARememberedAcceptanceCountsForThatAccountOnly(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $this->statusFor('2026-12')->remember($this->user('alice'), $session);

        $outstanding = $this->statusFor('2026-12')->isOutstanding($this->user('bob'), $session);

        self::assertTrue($outstanding);
    }

    private function statusFor(?string $version): TermsAcceptanceStatus
    {
        return new TermsAcceptanceStatus(new TermsVersion($version), self::getContainer()->get(TermsAcceptanceRepository::class));
    }

    private function user(string $username): User
    {
        return UserFactory::createOne(['username' => $username, 'email' => $username.'@example.test']);
    }

    private function accept(User $user, string $version): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new TermsAcceptance($user, TermsAcceptanceKind::Terms, $version, 'en', null));
        $entityManager->flush();
    }

    private function removeAcceptances(): void
    {
        self::getContainer()->get(EntityManagerInterface::class)->createQuery('DELETE FROM '.TermsAcceptance::class)->execute();
    }
}
