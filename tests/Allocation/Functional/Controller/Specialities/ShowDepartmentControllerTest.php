<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Specialities;

use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowDepartmentControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsDepartmentCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $department = DepartmentFactory::createOne(['name' => 'Stroke Unit']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/department/'.$department->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#department-name', 'Stroke Unit');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-coverage"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('/explore/allocation', $href);
        self::assertStringContainsString('department='.$department->getId(), $href);

        $actionHrefs = $crawler->filter('[data-testid="catalog-action"]')->each(
            static fn ($node): string => (string) $node->attr('href'),
        );
        self::assertContains('/statistics/top-lists/top_departments', $actionHrefs);
    }

    public function testListPageLinksToDepartmentsTopList(): void
    {
        $client = $this->createClientAsAreaUser();
        DepartmentFactory::createOne(['name' => 'Stroke Unit']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/department');

        self::assertResponseIsSuccessful();
        $href = $crawler->filter('[data-testid="catalog-top-list-action"]')->attr('href');
        self::assertNotNull($href);
        self::assertSame('/statistics/top-lists/top_departments', $href);
    }
}
