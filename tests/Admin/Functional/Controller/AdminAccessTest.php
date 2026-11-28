<?php

declare(strict_types=1);

namespace App\Tests\Admin\Functional\Controller;

use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Who may open the administration besides administrators: nobody. A plain user is in
 * `UserCrudControllerTest::testNonAdminUserGetsForbiddenOnUserIndex`, a participant in
 * `NavigationInspectorControllerTest::testOnlyAdminsHaveAccess`.
 */
#[ResetDatabase]
final class AdminAccessTest extends WebTestCase
{
    use Factories;

    public function testBoardMemberDoesNotGainAdminAccess(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::new()->asBoardMember()->create(['username' => 'alice']));

        $client->request(Request::METHOD_GET, '/admin');

        self::assertResponseStatusCodeSame(403);
    }
}
