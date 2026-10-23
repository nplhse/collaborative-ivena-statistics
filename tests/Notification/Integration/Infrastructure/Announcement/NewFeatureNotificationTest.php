<?php

declare(strict_types=1);

namespace App\Tests\Notification\Integration\Infrastructure\Announcement;

use App\Notification\Application\Definition\NotificationRegistry;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use App\Notification\Infrastructure\Announcement\NewFeatureNotification;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class NewFeatureNotificationTest extends KernelTestCase
{
    public function testIsAConfigurableWebAnnouncement(): void
    {
        $definition = self::getContainer()->get(NotificationRegistry::class)->get(NewFeatureNotification::TYPE);

        self::assertSame(NotificationCategory::Announcements, $definition->category);
        self::assertSame([NotificationChannel::InApp], $definition->defaultChannels);
        self::assertTrue($definition->configurable);
    }

    public function testTextsDependOnTheAnnouncedFeature(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        self::assertSame('Neu: Benachrichtigungen', $translator->trans('announcement.new_feature.title', ['feature' => 'notifications'], 'notification', 'de'));
        self::assertStringContainsString('legen Sie selbst in den Einstellungen fest', $translator->trans('announcement.new_feature.message', ['feature' => 'notifications'], 'notification', 'de'));
        self::assertSame('New feature', $translator->trans('announcement.new_feature.title', ['feature' => 'something_else'], 'notification', 'en'));
    }
}
