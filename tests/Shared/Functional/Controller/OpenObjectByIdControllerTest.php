<?php

declare(strict_types=1);

namespace App\Tests\Shared\Functional\Controller;

use App\Allocation\Infrastructure\Factory\InfectionFactory;
use App\Shared\UI\Http\Controller\OpensByIdTargets;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class OpenObjectByIdControllerTest extends WebTestCase
{
    use Factories;
    use InteractsWithAuthenticatedUser;

    public function testAdminIsRedirectedFromTheIdToTheCanonicalUrlAndSeesTheId(): void
    {
        $client = $this->createClientAsAdmin();
        $infection = InfectionFactory::createOne(['name' => 'MRSA']);

        $client->request(Request::METHOD_GET, '/explore/infection/'.$infection->getId());

        self::assertResponseRedirects('/explore/infection/'.$infection->getPublicIdString(), 302);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="admin-object-id"]', (string) $infection->getId());
    }

    public function testAdminIsRedirectedFromAUserIdToTheUsername(): void
    {
        $client = $this->createClientAsAdmin();
        $user = UserFactory::createOne(['username' => 'Ada.Lovelace']);

        $client->request(Request::METHOD_GET, '/explore/user/'.$user->getId());

        self::assertResponseRedirects('/explore/user/Ada.Lovelace', 302);
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-testid="admin-object-id"]', (string) $user->getId());
    }

    public function testParticipantsGetNotFoundForExistingIdsAndDoNotSeeTheId(): void
    {
        $client = $this->createClientAsParticipant();
        $infection = InfectionFactory::createOne();

        $client->request(Request::METHOD_GET, '/explore/infection/'.$infection->getId());
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/explore/infection/'.$infection->getPublicIdString());
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="admin-object-id"]');
    }

    public function testParticipantsGetNotFoundForUserIds(): void
    {
        $client = $this->createClientAsParticipant();
        $user = UserFactory::createOne(['username' => 'Ada.Lovelace']);

        $client->request(Request::METHOD_GET, '/explore/user/'.$user->getId());

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownIdsTypesAndPagesWithoutTheAttributeAreNotFound(): void
    {
        $client = $this->createClientAsAdmin();

        $client->request(Request::METHOD_GET, '/explore/infection/999999');
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/explore/unknown_type/1');
        self::assertResponseStatusCodeSame(404);

        $client->request(Request::METHOD_GET, '/explore/glossary/1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLegacyDigitsOnlyUsernameIsNotFoundInsteadOfRedirectingInALoop(): void
    {
        $client = $this->createClientAsAdmin();
        $user = UserFactory::createOne(['username' => '4242']);

        $client->request(Request::METHOD_GET, '/explore/user/'.$user->getId());

        self::assertResponseStatusCodeSame(404);
    }

    #[DataProvider('detailPageProvider')]
    public function testEveryExploreDetailPageOpensById(string $type, string $route, string $entity, string $parameter): void
    {
        self::bootKernel();
        $target = self::getContainer()->get(OpensByIdTargets::class)->find($type);

        self::assertNotNull($target, \sprintf('/explore/%s/{id} has no #[OpensById] detail route.', $type));
        self::assertSame($route, $target->route);
        self::assertSame($entity, $target->entity);
        self::assertSame($parameter, $target->parameter);
    }

    /**
     * @return iterable<string, array{string, string, class-string, string}>
     */
    public static function detailPageProvider(): iterable
    {
        $allocation = 'App\\Allocation\\Domain\\Entity\\';
        foreach ([
            'allocation' => 'Allocation',
            'assignment' => 'Assignment',
            'department' => 'Department',
            'dispatch_area' => 'DispatchArea',
            'hospital' => 'Hospital',
            'indication' => 'IndicationNormalized',
            'indication_group' => 'IndicationGroup',
            'infection' => 'Infection',
            'mci_case' => 'MciCase',
            'occasion' => 'Occasion',
            'secondary_transport' => 'SecondaryTransport',
            'speciality' => 'Speciality',
            'state' => 'State',
        ] as $type => $class) {
            /** @var class-string $entity */
            $entity = $allocation.$class;

            yield $type => [$type, 'app_explore_'.$type.'_show', $entity, 'publicId'];
        }

        yield 'user' => ['user', 'app_explore_user_show', User::class, 'username'];
    }

    private function createClientAsAdmin(): KernelBrowser
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]));

        return $client;
    }
}
