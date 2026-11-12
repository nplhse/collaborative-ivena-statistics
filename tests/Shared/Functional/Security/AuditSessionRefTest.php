<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Shared\Infrastructure\Audit\Http\AuditSessionRefSubscriber;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuditSessionRefTest extends WebTestCase
{
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
}
