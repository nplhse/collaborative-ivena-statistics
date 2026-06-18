<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AssignIndicationRawControllerTest extends WebTestCase
{
    use Factories;

    public function testAssignRedirectsWhenNotAuthenticated(): void
    {
        $client = self::createClient();
        $raw = IndicationRawFactory::createOne();
        $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/assign/%d', $raw->getId()));

        self::assertResponseRedirects('/login');
    }

    public function testAssignIsForbiddenForNonAdminUser(): void
    {
        $client = self::createClient();
        $raw = IndicationRawFactory::createOne();
        $user = UserFactory::createOne(['roles' => ['ROLE_USER']]);
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/assign/%d', $raw->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAssignLinksRawWithNormalized(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_ADMIN']]);
        $client->loginUser($admin);

        $normalized = IndicationNormalizedFactory::createOne([
            'code' => '123',
            'name' => 'Normalized Indication',
        ]);

        $raw = IndicationRawFactory::createOne([
            'code' => '123',
            'name' => 'Raw Indication',
        ]);

        $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/assign/%d', $raw->getId()));
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['indication_raw_assign[target_label]'] = 'Normalized Indication (123)';
        $form['indication_raw_assign[target]'] = (string) $normalized->getId();

        $client->submit($form);
        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Normalized indication has been assigned.');

        /** @var IndicationRawRepository $rawRepo */
        $rawRepo = self::getContainer()->get(IndicationRawRepository::class);
        $reloadedRaw = $rawRepo->find($raw->getId());

        self::assertNotNull($reloadedRaw, 'Raw entity should exist.');
        self::assertNotNull($reloadedRaw->getTarget(), 'Raw Indication should be assigned to a normalized Indication.');
        self::assertSame($normalized->getId(), $reloadedRaw->getTarget()->getId(), 'Assignment should match expected ID.');
        self::assertNotNull($reloadedRaw->getNormalized(), 'Raw Indication should mirror normalized for import and statistics.');
        self::assertSame($normalized->getId(), $reloadedRaw->getNormalized()->getId());
    }
}
