<?php

declare(strict_types=1);

namespace App\Tests\User\Functional\Security;

use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Account lock after 20 wrong passwords from any IP (#1762). The edge cases of counting and expiry are unit tests of
 * AccountLock; these tests cover the wiring into the sign-in form, the failure counters (#1487) and the password reset.
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

    public function testWrongPasswordsFromManyIpsLockTheAccount(): void
    {
        $user = UserFactory::new(['username' => 'target', 'email' => 'target@example.test', 'isVerified' => true])->create();

        // Four IPs with five wrong passwords each stay below login_throttling (IP + username) but add up to 20.
        foreach (['192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4'] as $ip) {
            for ($i = 0; $i < 5; ++$i) {
                $this->login('target', 'wrong-password', $ip);
                self::assertSelectorTextContains('.alert.alert-danger', 'Invalid credentials.');
            }
        }

        self::assertNotNull($this->reload($user)->getLoginLockedUntil());

        // The right password from a fifth IP is refused with the message of a wrong password.
        $this->login('target', 'password', '192.0.2.5');
        self::assertSelectorTextContains('.alert.alert-danger', 'Invalid credentials.');
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseRedirects();

        // An unknown username gets the same answer and counts for nobody.
        $this->login('nobody', 'wrong-password', '192.0.2.6');
        self::assertSelectorTextContains('.alert.alert-danger', 'Invalid credentials.');

        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM notification WHERE type = 'security.account_locked'"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE intent = 'user.login.account_locked'"));

        // Counters (#1487): 20 wrong passwords plus the unknown username, the refused right password, the lock.
        self::assertSame(
            ['account_locked' => 1, 'failure' => 21, 'refused' => 1],
            self::getContainer()->get(Connection::class)->fetchAllKeyValue('SELECT kind, COUNT(*) FROM login_failure_event GROUP BY kind ORDER BY kind'),
        );
    }

    public function testPasswordResetLiftsTheLock(): void
    {
        $user = UserFactory::new(['username' => 'locked', 'email' => 'locked@example.test', 'isVerified' => true])->create();
        $user->lockLoginUntil(new \DateTimeImmutable('+3 hours'));
        $this->entityManager()->flush();

        $token = self::getContainer()->get(ResetPasswordHelperInterface::class)->generateResetToken($user)->getToken();
        $this->client->request(Request::METHOD_GET, '/reset-password/reset/'.$token);
        $crawler = $this->client->followRedirect();
        $this->client->submit($crawler->selectButton('Save new password')->form([
            'reset_password_form[plainPassword][first]' => 'Correct-Horse-Battery-42',
            'reset_password_form[plainPassword][second]' => 'Correct-Horse-Battery-42',
        ]));
        self::assertResponseRedirects('/login');

        self::assertNull($this->reload($user)->getLoginLockedUntil());

        $this->login('locked', 'Correct-Horse-Battery-42', '192.0.2.9');
        self::assertResponseRedirects();
        $this->client->request(Request::METHOD_GET, '/settings/account');
        self::assertResponseIsSuccessful();
    }

    private function login(string $username, string $password, string $ip): void
    {
        $this->client->restart();
        $this->client->setServerParameter('REMOTE_ADDR', $ip);
        $this->acceptEssentialCookiesOnly($this->client);
        $crawler = $this->client->request(Request::METHOD_GET, '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            'login[username]' => $username,
            'login[password]' => $password,
        ]));
        if ($this->client->getResponse()->isRedirect('/login') || $this->client->getResponse()->isRedirect('http://localhost/login')) {
            $this->client->followRedirect();
        }
    }

    /** @param list<mixed> $params */
    private function countRows(string $sql, array $params = []): int
    {
        return (int) self::getContainer()->get(Connection::class)->fetchOne($sql, $params);
    }

    private function reload(User $user): User
    {
        $this->entityManager()->clear();
        $reloaded = $this->entityManager()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
