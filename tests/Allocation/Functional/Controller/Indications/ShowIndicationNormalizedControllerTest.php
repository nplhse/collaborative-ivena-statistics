<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Tests\Support\Security\InteractsWithAuthenticatedUser;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ShowIndicationNormalizedControllerTest extends WebTestCase
{
    use InteractsWithAuthenticatedUser;
    use Factories;

    public function testDetailPageShowsIndicationCatalogModules(): void
    {
        $client = $this->createClientAsAreaUser();
        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'STEMI',
            'code' => 101,
            'note' => null,
        ]);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication/'.$indication->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#indication-name', 'STEMI');
        self::assertSelectorExists('[data-testid="catalog-detail"]');
        self::assertSelectorNotExists('[data-testid="catalog-description"]');
        self::assertSelectorExists('[data-testid="catalog-coverage"]');
        self::assertSelectorExists('[data-testid="catalog-actions"]');
        self::assertSelectorTextContains('[data-testid="catalog-actions"]', 'View allocations');

        $href = $crawler->filter('[data-testid="catalog-action"]')->first()->attr('href');
        self::assertNotNull($href);
        self::assertStringContainsString('/explore/allocation', $href);
        self::assertStringContainsString('indication=101', $href);
    }

    public function testDetailPageShowsEditorialNoteInBasicInfoWhenPresent(): void
    {
        $client = $this->createClientAsAreaUser();
        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'Stroke',
            'code' => 202,
            'note' => 'Editorial definition for stroke.',
        ]);

        $client->request(Request::METHOD_GET, '/explore/indication/'.$indication->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="catalog-description"]');
        self::assertSelectorTextContains('[data-testid="catalog-editorial-note"]', 'Editorial definition for stroke.');
    }

    public function testParticipantDoesNotSeeNormalizationModule(): void
    {
        $client = $this->createClientAsAreaUser();
        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'STEMI',
            'code' => 101,
        ]);

        $client->request(Request::METHOD_GET, '/explore/indication/'.$indication->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="catalog-normalization"]');
    }

    public function testReviewerSeesMappedRawSynonymsAndWarnings(): void
    {
        $client = self::createClient();
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $client->loginUser($reviewer);

        $indication = IndicationNormalizedFactory::createOne([
            'name' => 'STEMI',
            'code' => 101,
        ]);
        IndicationRawFactory::createOne([
            'name' => 'STEMI alias',
            'code' => 1001,
            'target' => $indication,
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $client->request(Request::METHOD_GET, '/explore/indication/'.$indication->getPublicIdString());

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="catalog-normalization"]');
        self::assertSelectorTextContains('[data-testid="catalog-mapped-raw"]', 'STEMI alias');
        self::assertSelectorExists('[data-testid="catalog-quality-warnings"]');
        self::assertSelectorTextContains('[data-testid="catalog-actions"]', 'Raw indication review');
    }
}
