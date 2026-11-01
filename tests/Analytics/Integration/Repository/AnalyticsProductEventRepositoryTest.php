<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Integration\Repository;

use App\Analytics\Domain\Entity\AnalyticsProductEvent;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\Analytics\Infrastructure\Repository\AnalyticsProductEventRepository;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;

final class AnalyticsProductEventRepositoryTest extends DatabaseKernelTestCase
{
    private AnalyticsProductEventRepository $repository;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(AnalyticsProductEventRepository::class);
    }

    public function testEventAggregatesAndDepth(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
        $from = $this->repository->daysAgo(29);

        $userA = 'event-user-a';
        $userB = 'event-user-b';

        $this->saveEvent(UsageEventName::USER_REGISTERED, $userA, $now->modify('-20 days'), ['user_role' => 'ROLE_USER']);
        $this->saveEvent(UsageEventName::USER_BECAME_PARTICIPANT, $userA, $now->modify('-18 days'), ['user_role' => 'ROLE_PARTICIPANT']);
        $this->saveEvent(UsageEventName::IMPORT_COMPLETED, $userA, $now->modify('-10 days'), ['user_role' => 'ROLE_PARTICIPANT'], FeatureArea::Import);
        $this->saveEvent(UsageEventName::ANALYSIS_EXPLORER_RUN, $userA, $now->modify('-5 days'), ['user_role' => 'ROLE_PARTICIPANT'], FeatureArea::Analysis);
        $this->saveEvent(UsageEventName::ANALYSIS_EXPLORER_EXPORTED_CSV, $userA, $now->modify('-4 days'), ['user_role' => 'ROLE_PARTICIPANT'], FeatureArea::Export);

        $this->saveEvent(UsageEventName::USER_REGISTERED, $userB, $now->modify('-15 days'), ['user_role' => 'ROLE_USER']);
        $this->saveEvent(UsageEventName::USER_BECAME_PARTICIPANT, $userB, $now->modify('-14 days'), ['user_role' => 'ROLE_PARTICIPANT']);
        $this->saveEvent(UsageEventName::IMPORT_COMPLETED, $userB, $now->modify('-7 days'), ['user_role' => 'ROLE_PARTICIPANT'], FeatureArea::Import);
        $this->saveEvent(UsageEventName::ANALYSIS_EXPLORER_RUN, $userB, $now->modify('-2 days'), ['user_role' => 'ROLE_ADMIN'], FeatureArea::Analysis);

        $top = $this->repository->topEventsSince($from);
        self::assertNotEmpty($top);
        $eventNames = array_column($top, 'eventName');
        self::assertContains(UsageEventName::USER_REGISTERED, $eventNames);
        self::assertContains(UsageEventName::IMPORT_COMPLETED, $eventNames);

        $byRole = $this->repository->eventsByRoleSince($from);
        self::assertNotEmpty($byRole);
        self::assertContains('ROLE_PARTICIPANT', array_column($byRole, 'userRole'));

        $levels = $this->repository->maxEventLevelsByUserSince($from);
        self::assertSame(5, $levels[$userA]);
        self::assertSame(3, $levels[$userB]);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    private function saveEvent(
        string $eventName,
        string $analyticsUserKey,
        \DateTimeImmutable $occurredAt,
        array $context = [],
        ?FeatureArea $featureArea = null,
    ): void {
        $this->repository->save(new AnalyticsProductEvent(
            eventName: $eventName,
            featureArea: $featureArea,
            analyticsUserKey: $analyticsUserKey,
            visitorKey: 'visitor-event-1',
            sessionKey: 'session-event-1',
            context: $context,
            occurredAt: $occurredAt,
        ));
    }
}
