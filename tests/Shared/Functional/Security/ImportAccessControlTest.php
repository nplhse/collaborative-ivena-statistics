<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Security;

use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ImportAccessControlTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;
    use ResetDatabase;

    public function testImportRedirectsGuestsToLogin(): void
    {
        $client = self::createClient();
        $client->request(Request::METHOD_GET, '/import');

        self::assertResponseRedirects('/login');
    }

    public function testImportIsForbiddenForUserWithoutParticipantRole(): void
    {
        $client = self::createClient();
        $this->loginAsRoleUser($client);
        $client->request(Request::METHOD_GET, '/import');

        self::assertResponseStatusCodeSame(403);
    }

    public function testImportIsSuccessfulForParticipant(): void
    {
        $client = self::createClient();
        $participant = UserFactory::createOne([
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ])->_real();
        $client->loginUser($participant);
        $client->request(Request::METHOD_GET, '/import');

        self::assertResponseIsSuccessful();
    }
}
