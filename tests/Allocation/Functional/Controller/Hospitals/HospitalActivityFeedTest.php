<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Controller\Hospitals;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\User\Application\Activity\UserActivityDeduplicationKey;
use App\User\Domain\Entity\User;
use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Activity card on the hospital page (#488): loads the shared activity feed filtered by hospital.
 */
#[ResetDatabase]
final class HospitalActivityFeedTest extends WebTestCase
{
    use Factories;

    public function testHospitalPageLoadsTheFeedOfThisHospitalLazily(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $hospital = HospitalFactory::createOne();

        $crawler = $client->request(Request::METHOD_GET, '/explore/hospital/'.$hospital->getPublicIdString());

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('[data-testid="hospital-activity"] turbo-frame#activity-timeline');
        self::assertCount(1, $frame);
        self::assertSame('lazy', $frame->attr('loading'));
        self::assertSame('/activity/feed?hospital='.$hospital->getPublicIdString(), $frame->attr('src'));
    }

    public function testFeedShowsOnlyThisHospitalIncludingRemovedAccessAndOwnership(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $actor = UserFactory::createOne(['username' => 'grant-holder']);
        $actorId = $actor->getId();
        self::assertNotNull($actorId);
        $here = ['hospitalName' => 'Klinik A', 'hospitalPublicId' => '2ff031a9-3e94-5ece-8ecd-43ca1881d81f'];

        $this->record($actor, UserActivityType::HOSPITAL_ASSOCIATED, '2026-06-01', UserActivityDeduplicationKey::hospitalAssociated($actorId, 1, 1), $here);
        $this->record($actor, UserActivityType::HOSPITAL_DISASSOCIATED, '2026-06-02', UserActivityDeduplicationKey::hospitalDisassociated($actorId, 1, 1), $here);
        $this->record($actor, UserActivityType::HOSPITAL_OWNER_REVOKED, '2026-06-03', UserActivityDeduplicationKey::hospitalOwnerRevoked($actorId, 1), $here);
        $this->record($actor, UserActivityType::HOSPITAL_ASSOCIATED, '2026-06-04', UserActivityDeduplicationKey::hospitalAssociated($actorId, 2, 2), ['hospitalName' => 'Klinik B', 'hospitalPublicId' => 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb']);
        $this->record($actor, UserActivityType::JOINED, '2026-06-05', UserActivityDeduplicationKey::joined($actorId));

        $crawler = $client->request(
            Request::METHOD_GET,
            '/activity/feed?hospital=2ff031a9-3e94-5ece-8ecd-43ca1881d81f',
            [],
            [],
            ['HTTP_TURBO_FRAME' => 'activity-timeline'],
        );

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['hospital_owner_revoked', 'hospital_disassociated', 'hospital_associated'],
            $crawler->filter('[data-testid="activity-timeline-item"]')->each(static fn ($node): string => (string) $node->attr('data-activity-type')),
        );
        self::assertSelectorTextContains('[data-testid="activity-timeline-item"]', 'grant-holder');
        self::assertSelectorTextContains('[data-testid="activity-timeline-item"] [data-testid="activity-sentence"]', 'is no longer responsible for Klinik A');
    }

    public function testHospitalWithoutActivityShowsTheEmptyState(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        $client->request(Request::METHOD_GET, '/activity/feed?hospital=0f4c26b3-21d4-4f98-a6d1-fd3cec57623b');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="hospital-activity-empty"] .empty');
        self::assertSelectorNotExists('[data-testid="activity-timeline-empty"]');
    }

    public function testInvalidHospitalParameterFallsBackToTheProjectFeed(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));

        $client->request(Request::METHOD_GET, '/activity/feed?hospital=not-a-uuid');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('[data-testid="hospital-activity-empty"]');
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function record(User $user, UserActivityType $type, string $date, string $deduplicationKey, array $metadata = []): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserActivity(
            user: $user,
            type: $type,
            occurredAt: new \DateTimeImmutable($date.' 08:00:00'),
            deduplicationKey: $deduplicationKey,
            metadata: $metadata,
        ));
        $entityManager->flush();
    }
}
