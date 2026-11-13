<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Shared\Domain\Entity\CookieConsent;
use App\Shared\Infrastructure\Audit\Http\AuditSessionRefSubscriber;
use App\Tests\Support\Browser\CookieConsentTestHelper;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuditSessionRefTest extends WebTestCase
{
    use CookieConsentTestHelper;
    use Factories;

    public function testAuthenticatedSessionKeepsOneReferenceAcrossRequests(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne());

        $client->request('GET', '/dashboard');
        $first = $client->getRequest()->getSession()->get(AuditSessionRefSubscriber::SESSION_ATTRIBUTE);
        self::assertIsString($first);
        self::assertTrue(Uuid::isValid($first));

        $client->request('GET', '/dashboard');
        self::assertSame($first, $client->getRequest()->getSession()->get(AuditSessionRefSubscriber::SESSION_ATTRIBUTE));
    }

    public function testAnonymousSessionGetsNoReference(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');
        $client->request('GET', '/');

        self::assertTrue($client->getRequest()->hasPreviousSession(), 'precondition: the second request has a session');
        self::assertFalse($client->getRequest()->getSession()->has(AuditSessionRefSubscriber::SESSION_ATTRIBUTE));
    }

    /**
     * Linking the cookie consent happens in the authenticator's onAuthenticationSuccess(), before
     * LoginSuccessEvent. That entry must already carry the new session reference.
     */
    public function testEntriesWrittenDuringTheLoginCarryTheNewReference(): void
    {
        $client = self::createClient();
        UserFactory::createOne(['username' => 'session-login']);
        $this->acceptEssentialCookiesOnly($client);

        $form = $client->request(Request::METHOD_GET, '/login')->selectButton('Sign in')->form([
            'login[username]' => 'session-login',
            'login[password]' => 'password',
        ]);
        $client->submit($form);
        self::assertResponseRedirects();

        $ref = $client->getRequest()->getSession()->get(AuditSessionRefSubscriber::SESSION_ATTRIBUTE);
        self::assertIsString($ref);
        self::assertTrue(Uuid::isValid($ref));

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $row = $connection->fetchAssociative(
            "SELECT session_ref, actor_id FROM audit_log WHERE entity_class = :class AND action = 'update' ORDER BY id DESC LIMIT 1",
            ['class' => CookieConsent::class],
        );
        self::assertIsArray($row, 'logging in links the consent to the user');
        self::assertNotNull($row['actor_id']);
        self::assertSame($ref, $row['session_ref']);
    }
}
