<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\UsageEvents;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UsageAnalyticsConsentIntegrationTest extends KernelTestCase
{
    use Factories;

    public function testRecordForUserPersistsOnlyWithAnalyticsConsent(): void
    {
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $consentService = self::getContainer()->get(CookieConsentService::class);
        $analytics = self::getContainer()->get(UsageAnalytics::class);
        $eventRepository = self::getContainer()->get(AnalyticsProductEventRepository::class);

        $userProxy = UserFactory::createOne([
            'username' => 'analytics-consent-'.bin2hex(random_bytes(4)),
            'roles' => ['ROLE_USER', 'ROLE_PARTICIPANT'],
        ]);
        $user = $entityManager->find(User::class, $userProxy->getId());
        self::assertInstanceOf(User::class, $user);

        $analytics->recordForUser(UsageEventName::IMPORT_COMPLETED, $user, FeatureArea::Import);
        self::assertSame([], $eventRepository->findBy(['eventName' => UsageEventName::IMPORT_COMPLETED]));

        $request = Request::create('/');
        $consent = $consentService->resolveForRequest($request, $user);
        $consentService->applyPreference($consent, false, true, $user);

        $analytics->recordForUser(UsageEventName::IMPORT_COMPLETED, $user, FeatureArea::Import);

        $events = $eventRepository->findBy(['eventName' => UsageEventName::IMPORT_COMPLETED]);
        self::assertCount(1, $events);
        self::assertNotNull($events[0]->getAnalyticsUserKey());
        self::assertSame('ROLE_PARTICIPANT', $events[0]->getContext()['user_role'] ?? null);
        self::assertArrayNotHasKey('hospital_id', $events[0]->getContext());
        self::assertArrayNotHasKey('user_id', $events[0]->getContext());
    }
}
