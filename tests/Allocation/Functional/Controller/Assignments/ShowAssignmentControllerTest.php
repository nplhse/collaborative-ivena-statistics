<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Assignments;

use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowAssignmentControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsAssignmentCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $assignment = AssignmentFactory::createOne(['name' => 'EMS']);

        $crawler = $client->request(Request::METHOD_GET, '/explore/assignment/'.$assignment->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#assignment-name', 'EMS');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('assignment='.$assignment->getId(), $href);
    }
}
