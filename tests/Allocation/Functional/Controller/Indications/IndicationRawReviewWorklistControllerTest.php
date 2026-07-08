<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class IndicationRawReviewWorklistControllerTest extends WebTestCase
{
    use Factories;

    public function testAdminCanAccessWorklist(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/explore/indication/raw/review');

        self::assertResponseIsSuccessful();
    }

    public function testPlainUserIsForbidden(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => [UserRole::USER]]);
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, '/explore/indication/raw/review');

        self::assertResponseStatusCodeSame(403);
    }

    public function testWorklistRendersSegmentTabs(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication/raw/review');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('ul.nav-tabs .nav-link')->count());
        self::assertGreaterThan(0, $crawler->filter('a.nav-link:contains("Open")')->count());
        self::assertGreaterThan(0, $crawler->filter('a.nav-link:contains("Unreviewed")')->count());
        self::assertCount(0, $crawler->filter('a.nav-link:contains("Most frequent")'));
        self::assertCount(0, $crawler->filter('.badge.text-decoration-none'));
    }

    public function testWorklistSearchUsesAutosubmitWithoutSubmitButton(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication/raw/review');

        self::assertResponseIsSuccessful();
        $searchForm = $crawler->filter('[data-controller="autosubmit"]');
        self::assertCount(1, $searchForm);
        self::assertCount(1, $searchForm->filter('input[type="search"]'));
        self::assertCount(0, $searchForm->filter('button[type="submit"]'));
    }

    public function testStartMatchingRedirectsToFirstUnreviewedItem(): void
    {
        $client = self::createClient();
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $raw = IndicationRawFactory::createOne(['code' => 501, 'name' => 'Match Me']);
        $client->loginUser($reviewer);

        $client->request(Request::METHOD_GET, '/explore/indication/raw/review/start/matching');

        self::assertResponseRedirects(sprintf('/explore/indication/raw/review/%s?segment=unreviewed', $raw->getPublicIdString()));
    }

    public function testStartReviewingRedirectsToFirstNeedsReviewItem(): void
    {
        $client = self::createClient();
        $proposer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 502, 'name' => 'Review Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 502,
            'name' => 'Review Raw',
            'target' => $normalized,
            'firstMatchedBy' => $proposer,
            'firstMatchedAt' => new \DateTimeImmutable(),
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);
        $client->loginUser($reviewer);

        $client->request(Request::METHOD_GET, '/explore/indication/raw/review/start/reviewing');

        self::assertResponseRedirects(sprintf('/explore/indication/raw/review/%s?segment=needs_review', $raw->getPublicIdString()));
    }

    public function testWorklistCanSortByOccurrence(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        IndicationRawFactory::createOne(['code' => 601, 'name' => 'Low Occurrence']);
        IndicationRawFactory::createOne(['code' => 602, 'name' => 'High Occurrence']);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, '/explore/indication/raw/review', [
            'segment' => 'unreviewed',
            'sortBy' => 'occurrence',
            'orderBy' => 'desc',
        ]);

        self::assertResponseIsSuccessful();
    }

    public function testLegacyTopOpenSegmentRedirectsToOpenView(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, '/explore/indication/raw/review', ['segment' => 'top_open']);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('a.nav-link.active:contains("Open")')->count());
    }

    public function testStartMatchingWithEmptyQueueRedirectsToWorklist(): void
    {
        $client = self::createClient();
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $client->loginUser($reviewer);

        $client->request(Request::METHOD_GET, '/explore/indication/raw/review/start/matching');

        self::assertResponseRedirects('/explore/indication/raw/review?segment=unreviewed');
    }
}
