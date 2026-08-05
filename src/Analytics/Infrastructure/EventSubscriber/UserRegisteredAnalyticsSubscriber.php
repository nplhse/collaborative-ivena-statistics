<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\EventSubscriber;

use App\Analytics\Application\UsageEvents\UsageAnalytics;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Domain\UsageEventName;
use App\User\Application\Event\UserRegistered;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final readonly class UserRegisteredAnalyticsSubscriber
{
    public function __construct(
        private UsageAnalytics $usageAnalytics,
    ) {
    }

    #[AsEventListener(event: UserRegistered::class)]
    public function onUserRegistered(UserRegistered $event): void
    {
        unset($event);
        $this->usageAnalytics->record(
            UsageEventName::USER_REGISTERED,
            FeatureArea::Other,
        );
    }
}
