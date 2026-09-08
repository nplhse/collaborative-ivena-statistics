<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\EventSubscriber;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\UsageEventName;
use App\Analytics\Infrastructure\EventSubscriber\UserBecameParticipantAnalyticsSubscriber;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;
use App\Shared\Infrastructure\Consent\CookieConsentService;
use App\User\Application\Event\UserBecameParticipant;
use App\User\Domain\Entity\User;
use App\User\Domain\Factory\UserFactory;
use App\User\Infrastructure\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class UserBecameParticipantAnalyticsSubscriberTest extends KernelTestCase
{
    use Factories;

    public function testRecordsUsageEventForExistingUserWithConsent(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $consentService = self::getContainer()->get(CookieConsentService::class);

        $userProxy = UserFactory::createOne([
            'username' => 'became-participant-'.bin2hex(random_bytes(4)),
        ]);
        $user = $entityManager->find(User::class, $userProxy->getId());
        self::assertInstanceOf(User::class, $user);

        $request = Request::create('/');
        $consent = $consentService->resolveForRequest($request, $user);
        $consentService->applyPreference($consent, true, $user);

        $this->subscriber()->onUserBecameParticipant(new UserBecameParticipant((int) $user->getId()));

        $events = self::getContainer()->get(AnalyticsProductEventRepository::class)
            ->findBy(['eventName' => UsageEventName::USER_BECAME_PARTICIPANT]);
        self::assertCount(1, $events);
    }

    public function testSkipsUnknownUser(): void
    {
        $this->subscriber()->onUserBecameParticipant(new UserBecameParticipant(999_999_999));

        $events = self::getContainer()->get(AnalyticsProductEventRepository::class)
            ->findBy(['eventName' => UsageEventName::USER_BECAME_PARTICIPANT]);
        self::assertSame([], $events);
    }

    private function subscriber(): UserBecameParticipantAnalyticsSubscriber
    {
        return new UserBecameParticipantAnalyticsSubscriber(
            self::getContainer()->get(UsageAnalytics::class),
            self::getContainer()->get(UserRepository::class),
        );
    }
}
