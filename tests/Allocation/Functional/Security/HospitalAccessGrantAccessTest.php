<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Security;

use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalAccessGrantFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Repository\HospitalAccessGrantRepository;
use App\Statistics\Application\Contract\HospitalAccessInterface;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class HospitalAccessGrantAccessTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testOwnerCanViewAccessGrantMatrix(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grant-candidate']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access');

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('User permissions', $content);
        self::assertStringContainsString('Access Statistics', $content);
        self::assertStringContainsString('access-grants-matrix__permission-label', $content);
        self::assertStringContainsString('Manage access', $content);
        self::assertStringContainsString('/hospitals/'.$hospital->getId().'/edit/access', $content);
        self::assertStringContainsString('Grant access', $content);
        self::assertStringNotContainsString('<datalist', $content);
    }

    public function testOwnerCanCreateAccessGrantViaHiddenUserId(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $candidate = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grant-candidate']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access/new');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('datalist-chooser', (string) $client->getResponse()->getContent());

        $form = $crawler->filter('form')->form([
            'hospital_access_grant[user]' => (string) $candidate->getId(),
            'hospital_access_grant[permissionChoices]' => [(string) HospitalPermission::View->value],
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access');

        /** @var HospitalAccessGrantRepository $repository */
        $repository = self::getContainer()->get(HospitalAccessGrantRepository::class);
        $grant = $repository->findForUserAndHospital($candidate, $hospital);

        self::assertNotNull($grant);
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::View));
    }

    public function testAdminCanCreateAccessGrantViaHiddenUserId(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $candidate = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'admin-grant-candidate']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access/new');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'hospital_access_grant[user]' => (string) $candidate->getId(),
            'hospital_access_grant[permissionChoices]' => [(string) HospitalPermission::View->value],
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access');

        /** @var HospitalAccessGrantRepository $repository */
        $repository = self::getContainer()->get(HospitalAccessGrantRepository::class);
        $grant = $repository->findForUserAndHospital($candidate, $hospital);

        self::assertNotNull($grant);
        self::assertTrue(HospitalPermissionMask::has($grant->getPermissions(), HospitalPermission::View));
    }

    public function testOwnerCanEditAccessGrantPermissions(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grant-edit-user']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $grant = HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        $client->loginUser($owner);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/hospitals/'.$hospital->getId().'/edit/access/'.$grant->getId(),
        );
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('grant-edit-user', (string) $client->getResponse()->getContent());

        $form = $crawler->filter('form')->form([
            'hospital_access_grant[permissionChoices]' => [
                (string) HospitalPermission::View->value,
                (string) HospitalPermission::Statistics->value,
            ],
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access');

        /** @var HospitalAccessGrantRepository $repository */
        $repository = self::getContainer()->get(HospitalAccessGrantRepository::class);
        $updatedGrant = $repository->findForUserAndHospital($grantee, $hospital);

        self::assertNotNull($updatedGrant);
        self::assertTrue(HospitalPermissionMask::has($updatedGrant->getPermissions(), HospitalPermission::Statistics));
    }

    public function testAdminCanEditAccessGrantPermissions(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'admin-edit-grantee']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $grant = HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(
            Request::METHOD_GET,
            '/hospitals/'.$hospital->getId().'/edit/access/'.$grant->getId(),
        );
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'hospital_access_grant[permissionChoices]' => [
                (string) HospitalPermission::View->value,
                (string) HospitalPermission::Statistics->value,
            ],
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access');

        /** @var HospitalAccessGrantRepository $repository */
        $repository = self::getContainer()->get(HospitalAccessGrantRepository::class);
        $updatedGrant = $repository->findForUserAndHospital($grantee, $hospital);

        self::assertNotNull($updatedGrant);
        self::assertTrue(HospitalPermissionMask::has($updatedGrant->getPermissions(), HospitalPermission::Statistics));
    }

    public function testOwnerCanDeleteAccessGrant(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'grant-delete-user']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $grant = HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        $client->loginUser($owner);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access');
        self::assertResponseIsSuccessful();

        $deleteForm = $crawler->filter('form[action$="/edit/access/'.$grant->getId().'/delete"]')->form();
        $client->submit($deleteForm);

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access');

        /** @var HospitalAccessGrantRepository $repository */
        $repository = self::getContainer()->get(HospitalAccessGrantRepository::class);

        self::assertNull($repository->findForUserAndHospital($grantee, $hospital));
    }

    public function testAdminCanDeleteAccessGrant(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'], 'username' => 'admin-delete-grantee']);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $grant = HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::View]),
            'createdBy' => $owner,
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access');
        self::assertResponseIsSuccessful();

        $deleteForm = $crawler->filter('form[action$="/edit/access/'.$grant->getId().'/delete"]')->form();
        $client->submit($deleteForm);

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access');

        /** @var HospitalAccessGrantRepository $repository */
        $repository = self::getContainer()->get(HospitalAccessGrantRepository::class);

        self::assertNull($repository->findForUserAndHospital($grantee, $hospital));
    }

    public function testLegacyAccessGrantsUrlRedirectsToEditAccess(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $client->loginUser($owner);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/access-grants');

        self::assertResponseRedirects('/hospitals/'.$hospital->getId().'/edit/access', Response::HTTP_MOVED_PERMANENTLY);
    }

    public function testNonOwnerCannotManageAccessGrants(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $intruder = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        $client->loginUser($intruder);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCanViewAccessGrantMatrix(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);

        $client->loginUser($admin);
        $client->request(Request::METHOD_GET, '/hospitals/'.$hospital->getId().'/edit/access');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('User permissions', (string) $client->getResponse()->getContent());
    }

    public function testGranteeWithStatisticsButWithoutBenchmarkingCannotUseBenchmarkingScope(): void
    {
        $client = self::createClient();

        $owner = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        $grantee = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]);
        StateFactory::createOne();
        DispatchAreaFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);

        HospitalAccessGrantFactory::createOne([
            'hospital' => $hospital,
            'user' => $grantee,
            'permissions' => HospitalPermissionMask::fromPermissions([HospitalPermission::Statistics]),
            'createdBy' => $owner,
        ]);

        $access = $client->getContainer()->get(HospitalAccessInterface::class);

        self::assertTrue($access->canUseMyHospitalsScope($grantee));
        self::assertFalse($access->canUseBenchmarkingScope($grantee));
    }
}
