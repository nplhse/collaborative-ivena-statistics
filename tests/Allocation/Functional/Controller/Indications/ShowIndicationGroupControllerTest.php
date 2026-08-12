<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Domain\Entity\IndicationGroup;
use App\Allocation\Infrastructure\Factory\IndicationGroupFactory;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowIndicationGroupControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testListAndDetailPagesAreReachable(): void
    {
        $client = $this->createClientAsAreaUser();
        $indication = IndicationNormalizedFactory::createOne(['name' => 'STEMI', 'code' => 101]);
        $group = IndicationGroupFactory::createOne([
            'name' => 'Cardiac',
            'description' => 'Cardiac indication group.',
        ]);

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $groupEntity = $entityManager->find(IndicationGroup::class, $group->getId());
        self::assertNotNull($groupEntity);
        $groupEntity->addIndication($indication);
        $entityManager->flush();

        $client->request(Request::METHOD_GET, '/explore/indication_group');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', 'Cardiac');

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication_group/'.$group->getPublicIdString());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#indication-group-name', 'Cardiac');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-coverage"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');
        self::assertSelectorNotExists('[data-testid="catalog-description"]');
        self::assertSelectorTextContains('[data-testid="catalog-editorial-note"]', 'Cardiac indication group.');
        self::assertSelectorTextContains('[data-testid="catalog-related-entities"]', 'STEMI');
        self::assertSelectorExists('[data-testid="catalog-basic-info"] a[href^="/explore/user/"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('/statistics/indication-group/', $href);
        self::assertStringContainsString((string) $group->getId(), $href);
    }
}
