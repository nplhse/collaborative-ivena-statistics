<?php

declare(strict_types=1);

namespace App\Tests\Content\Functional\Controller;

use App\User\Domain\Entity\User;
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
 * Every list of activities shows the time and the reaction bar in one row below the entry.
 */
#[ResetDatabase]
final class ActivityReactionBarsTest extends WebTestCase
{
    use Factories;

    private const string HOSPITAL = '2ff031a9-3e94-5ece-8ecd-43ca1881d81f';

    #[DataProvider('activityLists')]
    public function testListShowsTimeAndReactionBarInOneRow(string $url): void
    {
        $client = self::createClient();
        $client->loginUser(UserFactory::createOne(['roles' => ['ROLE_USER', 'ROLE_PARTICIPANT']]));
        $activityId = $this->activity(UserFactory::createOne(['username' => 'activity-owner']));

        $crawler = $client->request(Request::METHOD_GET, $url);

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('[data-testid="activity-meta"]');
        self::assertCount(1, $row);
        self::assertCount(1, $row->filter('time'));
        self::assertCount(1, $row->filter('#reactions-activity-'.$activityId.'[data-controller="reactions-loader"]'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function activityLists(): iterable
    {
        yield 'dashboard' => ['/dashboard/activity'];
        yield 'timeline' => ['/activity'];
        yield 'hospital feed' => ['/activity/feed?hospital='.self::HOSPITAL];
        yield 'user profile' => ['/explore/user/activity-owner'];
    }

    private function activity(User $user): int
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $activity = new UserActivity(
            $user,
            UserActivityType::HOSPITAL_ASSOCIATED,
            new \DateTimeImmutable('2026-11-01 10:00:00'),
            'associated-'.$user->getId(),
            ['hospitalName' => 'Klinik A', 'hospitalPublicId' => self::HOSPITAL],
        );
        $entityManager->persist($activity);
        $entityManager->flush();

        return (int) $activity->getId();
    }
}
