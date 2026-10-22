<?php

declare(strict_types=1);

namespace App\Tests\Notification\Unit\Application\Preference;

use App\Notification\Application\Definition\NotificationDefinition;
use App\Notification\Application\Preference\NotificationChannelResolver;
use App\Notification\Application\Preference\NotificationPreferenceOverrides;
use App\Notification\Domain\Enum\NotificationCategory;
use App\Notification\Domain\Enum\NotificationChannel;
use PHPUnit\Framework\TestCase;

final class NotificationChannelResolverTest extends TestCase
{
    private const array ALL = [NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Toast];

    public function testUsesDefaultsWithoutOverrides(): void
    {
        $resolved = $this->resolve($this->definition([NotificationChannel::InApp, NotificationChannel::Toast]), NotificationPreferenceOverrides::none());

        self::assertSame([NotificationChannel::InApp, NotificationChannel::Toast], $resolved);
    }

    public function testCategoryOverrideChangesDefault(): void
    {
        $resolved = $this->resolve(
            $this->definition([NotificationChannel::InApp]),
            new NotificationPreferenceOverrides(categoryOverrides: ['email' => true]),
        );

        self::assertSame([NotificationChannel::InApp, NotificationChannel::Email], $resolved);
    }

    public function testTypeOverrideBeatsCategoryOverride(): void
    {
        $resolved = $this->resolve(
            $this->definition([NotificationChannel::InApp]),
            new NotificationPreferenceOverrides(
                typeOverrides: ['email' => false, 'in_app' => true],
                categoryOverrides: ['email' => true, 'in_app' => false],
            ),
        );

        self::assertSame([NotificationChannel::InApp], $resolved);
    }

    public function testMandatoryChannelIgnoresOverrides(): void
    {
        $resolved = $this->resolve(
            $this->definition([NotificationChannel::InApp], mandatory: [NotificationChannel::Email]),
            new NotificationPreferenceOverrides(typeOverrides: ['email' => false], categoryOverrides: ['email' => false]),
        );

        self::assertSame([NotificationChannel::InApp, NotificationChannel::Email], $resolved);
    }

    public function testNonConfigurableTypeIgnoresOverrides(): void
    {
        $resolved = $this->resolve(
            $this->definition([NotificationChannel::InApp], configurable: false),
            new NotificationPreferenceOverrides(typeOverrides: ['in_app' => false, 'email' => true]),
        );

        self::assertSame([NotificationChannel::InApp], $resolved);
    }

    public function testToastIsDroppedWithoutInApp(): void
    {
        $resolved = $this->resolve(
            $this->definition([NotificationChannel::InApp, NotificationChannel::Toast]),
            new NotificationPreferenceOverrides(typeOverrides: ['in_app' => false]),
        );

        self::assertSame([], $resolved);
    }

    public function testOnlyAvailableChannelsAreResolved(): void
    {
        $resolved = $this->resolve(
            new NotificationDefinition(
                type: 'test.example',
                category: NotificationCategory::Announcements,
                defaultChannels: [NotificationChannel::InApp],
                availableChannels: [NotificationChannel::InApp],
            ),
            new NotificationPreferenceOverrides(typeOverrides: ['email' => true, 'toast' => true]),
        );

        self::assertSame([NotificationChannel::InApp], $resolved);
    }

    /**
     * @return list<NotificationChannel>
     */
    private function resolve(NotificationDefinition $definition, NotificationPreferenceOverrides $overrides): array
    {
        return new NotificationChannelResolver()->resolve($definition, $overrides)->channels;
    }

    /**
     * @param list<NotificationChannel> $defaults
     * @param list<NotificationChannel> $mandatory
     */
    private function definition(array $defaults, array $mandatory = [], bool $configurable = true): NotificationDefinition
    {
        return new NotificationDefinition(
            type: 'test.example',
            category: NotificationCategory::Announcements,
            defaultChannels: $defaults,
            availableChannels: self::ALL,
            configurable: $configurable,
            mandatoryChannels: $mandatory,
        );
    }
}
