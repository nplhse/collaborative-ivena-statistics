<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Security;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Security\AccountLock\AccountLock;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Wiring of the account lock (#1762) into the sign-in form and the password reset. Counting, expiry and which failures
 * count are unit tests of AccountLock and AccountLockListener.
 */
#[ResetDatabase]
final class AccountLockTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = self::createClient();
        $this->client->getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testAWrongPasswordInTheFormCountsTowardsTheLock(): void
    {
        $user = $this->user('target');
        $this->failPasswordsWithoutTheForm($user, 19);

        $this->login('target', 'wrong-password');

        self::assertNotNull($this->reload($user)->getLoginLockedUntil());
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE type = 'security.account_locked'",
        ));
    }

    public function testALockedAccountRefusesTheRightPasswordLikeAWrongOne(): void
    {
        $this->lock($this->user('locked'));

        $this->login('locked', 'password');

        self::assertSelectorTextContains('.alert.alert-danger', 'Invalid credentials.');
    }

    public function testPasswordResetLiftsTheLock(): void
    {
        $user = $this->user('locked');
        $this->lock($user);
        $token = self::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user)->getToken();
        $this->client->request(Request::METHOD_GET, '/reset-password/reset/'.$token);
        $crawler = $this->client->followRedirect();

        $this->client->submit($crawler->selectButton('Save new password')->form([
            'reset_password_form[plainPassword][first]' => 'Correct-Horse-Battery-42',
            'reset_password_form[plainPassword][second]' => 'Correct-Horse-Battery-42',
        ]));

        self::assertResponseRedirects('/login');
        self::assertNull($this->reload($user)->getLoginLockedUntil());
    }

    private function user(string $username): User
    {
        return UserFactory::new(['username' => $username, 'email' => $username.'@example.test', 'isVerified' => true])->create();
    }

    private function lock(User $user): void
    {
        $user->lockLoginUntil(new \DateTimeImmutable('+3 hours'));
        self::getContainer()->get(EntityManagerInterface::class)->flush();
    }

    /** Counted the same way as through the form, but without 19 requests (login_throttling would stop them anyway). */
    private function failPasswordsWithoutTheForm(User $user, int $times): void
    {
        $accountLock = self::getContainer()->get(AccountLock::class);
        for ($i = 0; $i < $times; ++$i) {
            $accountLock->recordFailedPassword($user);
        }
    }

    private function login(string $username, string $password): void
    {
        $this->acceptEssentialCookiesOnly($this->client);
        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'login[username]' => $username,
            'login[password]' => $password,
        ]));
        if ($this->client->getResponse()->isRedirect()) {
            $this->client->followRedirect();
        }
    }

    private function reload(User $user): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $reloaded = $entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
