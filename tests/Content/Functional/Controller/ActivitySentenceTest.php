<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\User\Domain\Entity\UserActivity;
use App\User\Domain\Enum\UserActivityType;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * An activity reads as one sentence: who did what with what.
 */
#[ResetDatabase]
final class ActivitySentenceTest extends WebTestCase
{
    use Factories;

    private const string HOSPITAL = '2ff031a9-3e94-5ece-8ecd-43ca1881d81f';

    /**
     * @param array<string, mixed> $metadata
     */
    #[DataProvider('sentences')]
    public function testActivityReadsAsASentence(UserActivityType $type, array $metadata, string $expected): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $this->record($type, $metadata);

        // Ended access and ownership only appear in the feed of the hospital.
        $url = \in_array($type, [UserActivityType::HOSPITAL_DISASSOCIATED, UserActivityType::HOSPITAL_OWNER_REVOKED], true)
            ? '/activity/feed?hospital='.self::HOSPITAL
            : '/activity';
        $crawler = $client->request(Request::METHOD_GET, $url, [], [], ['HTTP_TURBO_FRAME' => 'activity-timeline']);

        self::assertResponseIsSuccessful();
        // AA: the initials of the inline avatar in front of the name.
        self::assertSame('AA '.$expected, trim((string) preg_replace('/\s+/u', ' ', $crawler->filter('[data-testid="activity-sentence"]')->text())));
    }

    /**
     * @return iterable<string, array{UserActivityType, array<string, mixed>, string}>
     */
    public static function sentences(): iterable
    {
        $hospital = ['hospitalName' => 'Klinik A', 'hospitalPublicId' => self::HOSPITAL];

        yield 'joined' => [UserActivityType::JOINED, [], 'Ada Admin joined the platform.'];
        yield 'first import' => [UserActivityType::FIRST_IMPORT, ['milestone' => 1, ...$hospital], 'Ada Admin imported data for Klinik A for the first time.'];
        yield 'milestone' => [UserActivityType::IMPORT_MILESTONE, ['milestone' => 10, ...$hospital], 'Ada Admin has completed 10 data imports in total, most recently for Klinik A.'];
        yield 'milestone without hospital' => [UserActivityType::IMPORT_MILESTONE, ['milestone' => 10], 'Ada Admin has completed 10 data imports in total.'];
        yield 'post' => [UserActivityType::POST_PUBLISHED, ['title' => 'Launch'], 'Ada Admin published the post “Launch”.'];
        yield 'comment' => [UserActivityType::COMMENT_CREATED, ['postTitle' => 'Launch'], 'Ada Admin commented on “Launch”.'];
        yield 'comment without post' => [UserActivityType::COMMENT_CREATED, [], 'Ada Admin commented on a post.'];
        yield 'associated' => [UserActivityType::HOSPITAL_ASSOCIATED, $hospital, 'Ada Admin is now associated with Klinik A.'];
        yield 'disassociated' => [UserActivityType::HOSPITAL_DISASSOCIATED, $hospital, 'Ada Admin is no longer associated with Klinik A.'];
        yield 'owner granted' => [UserActivityType::HOSPITAL_OWNER_GRANTED, $hospital, 'Ada Admin is now responsible for Klinik A.'];
        yield 'owner revoked' => [UserActivityType::HOSPITAL_OWNER_REVOKED, $hospital, 'Ada Admin is no longer responsible for Klinik A.'];
    }

    public function testNamesAndTitlesAreEscapedInTheSentence(): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $this->record(UserActivityType::POST_PUBLISHED, ['title' => '<img src=x onerror=alert(1)>'], '<b>Ada</b>');

        $crawler = $client->request(Request::METHOD_GET, '/activity');

        $sentence = $crawler->filter('[data-testid="activity-sentence"]');
        self::assertCount(0, $sentence->filter('img'));
        self::assertCount(0, $sentence->filter('b'));
        self::assertStringContainsString('<img src=x onerror=alert(1)>', $sentence->text());
        self::assertStringContainsString('<b>Ada</b>', $sentence->text());
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function record(UserActivityType $type, array $metadata, string $displayName = 'Ada Admin'): void
    {
        $actor = UserFactory::createOne(['displayName' => $displayName]);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new UserActivity($actor, $type, new \DateTimeImmutable('2026-01-01 10:00:00'), 'sentence-'.$actor->getId(), $metadata));
        $entityManager->flush();
    }
}
