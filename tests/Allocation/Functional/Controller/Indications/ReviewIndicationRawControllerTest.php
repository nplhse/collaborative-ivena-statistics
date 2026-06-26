<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Indications;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Repository\IndicationRawRepository;
use App\User\Domain\Factory\UserFactory;
use App\User\Domain\Security\UserRole;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class ReviewIndicationRawControllerTest extends WebTestCase
{
    use Factories;

    public function testParticipantCanViewButCannotPropose(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::PARTICIPANT]]);
        $raw = IndicationRawFactory::createOne();
        $client->loginUser($user);

        $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/review/%d', $raw->getId()));
        self::assertResponseIsSuccessful();
    }

    public function testProposeMatchSetsNeedsReview(): void
    {
        $client = self::createClient();
        $user = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 111, 'name' => 'Test Norm']);
        $raw = IndicationRawFactory::createOne(['code' => 111, 'name' => 'Test Raw']);
        $client->loginUser($user);

        $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/review/%d', $raw->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Propose match')->form();
        $form['indication_raw_review[target_label]'] = 'Test Norm (111)';
        $form['indication_raw_review[target]'] = (string) $normalized->getId();
        $client->submit($form);

        /** @var IndicationRawRepository $repo */
        $repo = self::getContainer()->get(IndicationRawRepository::class);
        $reloaded = $repo->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame(IndicationRawReviewStatus::NeedsReview, $reloaded->getReviewStatus());
        self::assertNotNull($reloaded->getFirstMatchedBy());
    }

    public function testApproveMatchByOtherReviewer(): void
    {
        $client = self::createClient();
        $proposer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 222, 'name' => 'Approve Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 222,
            'name' => 'Approve Raw',
            'target' => $normalized,
            'firstMatchedBy' => $proposer,
            'firstMatchedAt' => new \DateTimeImmutable(),
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $client->loginUser($reviewer);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/review/%d', $raw->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Accept')->form();
        $client->submit($form);

        /** @var IndicationRawRepository $repo */
        $repo = self::getContainer()->get(IndicationRawRepository::class);
        $reloaded = $repo->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame(IndicationRawReviewStatus::Matched, $reloaded->getReviewStatus());
    }

    public function testAdminCanMatchAndApproveInOneStep(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS, UserRole::ADMIN],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 333, 'name' => 'Fast Norm']);
        $raw = IndicationRawFactory::createOne(['code' => 333, 'name' => 'Fast Raw']);
        $client->loginUser($admin);

        $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/review/%d', $raw->getId()));
        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Match and approve')->form();
        $form['indication_raw_review[target_label]'] = 'Fast Norm (333)';
        $form['indication_raw_review[target]'] = (string) $normalized->getId();
        $client->submit($form);

        /** @var IndicationRawRepository $repo */
        $repo = self::getContainer()->get(IndicationRawRepository::class);
        $reloaded = $repo->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame(IndicationRawReviewStatus::Matched, $reloaded->getReviewStatus());
        self::assertSame($admin->getId(), $reloaded->getFirstMatchedBy()?->getId());
        self::assertSame($admin->getId(), $reloaded->getReviewedBy()?->getId());
    }

    public function testNeedsReviewShowsAcceptButNotPropose(): void
    {
        $client = self::createClient();
        $proposer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $reviewer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 444, 'name' => 'Review Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 444,
            'name' => 'Review Raw',
            'target' => $normalized,
            'firstMatchedBy' => $proposer,
            'firstMatchedAt' => new \DateTimeImmutable(),
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $client->loginUser($reviewer);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/review/%d', $raw->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Indication: Review Raw (444)', $crawler->filter('h2.page-title')->text());
        self::assertStringContainsString($proposer->getUserIdentifier(), $crawler->filter('.card-body dl')->text());

        $actions = $crawler->filter('#indication-review-actions');
        self::assertCount(1, $actions->selectButton('Accept'));
        self::assertCount(
            0,
            $actions->filterXPath('//button[contains(normalize-space(.), "Propose match")]')
        );
    }

    public function testAdminNeedsReviewShowsApproveMatchNotAccept(): void
    {
        $client = self::createClient();
        $proposer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $admin = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS, UserRole::ADMIN],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 446, 'name' => 'Admin Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 446,
            'name' => 'Admin Review Raw',
            'target' => $normalized,
            'firstMatchedBy' => $proposer,
            'firstMatchedAt' => new \DateTimeImmutable(),
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/review/%d', $raw->getId()));
        self::assertResponseIsSuccessful();

        $actions = $crawler->filter('#indication-review-actions');
        self::assertCount(1, $actions->selectButton('Approve match'));
        self::assertCount(0, $actions->selectButton('Accept'));
        self::assertCount(0, $actions->selectButton('Match and approve'));
    }

    public function testAdminCanApproveExistingProposalOnNeedsReview(): void
    {
        $client = self::createClient();
        $proposer = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS],
        ]);
        $admin = UserFactory::createOne([
            'roles' => [UserRole::USER, UserRole::PARTICIPANT, UserRole::REVIEW_INDICATIONS, UserRole::ADMIN],
        ]);
        $normalized = IndicationNormalizedFactory::createOne(['code' => 447, 'name' => 'Admin Approve Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 447,
            'name' => 'Admin Approve Raw',
            'target' => $normalized,
            'firstMatchedBy' => $proposer,
            'firstMatchedAt' => new \DateTimeImmutable(),
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $client->loginUser($admin);
        $crawler = $client->request(Request::METHOD_GET, sprintf('/explore/indication/raw/review/%d', $raw->getId()));
        $form = $crawler->selectButton('Approve match')->form();
        $client->submit($form);

        /** @var IndicationRawRepository $repo */
        $repo = self::getContainer()->get(IndicationRawRepository::class);
        $reloaded = $repo->find($raw->getId());
        self::assertNotNull($reloaded);
        self::assertSame(IndicationRawReviewStatus::Matched, $reloaded->getReviewStatus());
        self::assertSame($admin->getId(), $reloaded->getReviewedBy()?->getId());
    }

    public function testNotMatchableReviewRendersWithoutFormError(): void
    {
        $client = self::createClient();
        $admin = UserFactory::createOne(['roles' => [UserRole::USER, UserRole::ADMIN]]);
        $raw = IndicationRawFactory::createOne([
            'code' => 555,
            'name' => 'Closed Raw',
            'reviewStatus' => IndicationRawReviewStatus::NotMatchable,
        ]);
        $client->loginUser($admin);

        $client->request(Request::METHOD_GET, sprintf(
            '/explore/indication/raw/review/%d?segment=not_matchable',
            $raw->getId(),
        ));

        self::assertResponseIsSuccessful();
    }
}
