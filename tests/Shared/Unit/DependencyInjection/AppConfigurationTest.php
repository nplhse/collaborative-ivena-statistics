<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\DependencyInjection;

use App\Shared\Infrastructure\DependencyInjection\AppConfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class AppConfigurationTest extends TestCase
{
    public function testBlogDefaultsAreApplied(): void
    {
        $config = [
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
        ];

        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$config]);

        self::assertSame('Blog', $processed['blog']['title']);
        self::assertSame('Neuigkeiten, Updates und Hintergrundinformationen.', $processed['blog']['description']);
        self::assertSame(2025, $processed['meta']['copyright_start_year']);
        self::assertSame('', $processed['meta']['hoster']['name']);
        self::assertSame('', $processed['meta']['hoster']['url']);
        self::assertSame('csv', $processed['import']['reject_writer']);
        self::assertSame(4, $processed['feedback']['spam']['min_submission_seconds']);
        self::assertSame(1800, $processed['feedback']['spam']['long_message_threshold']);
        self::assertSame(4000, $processed['feedback']['spam']['max_message_length']);
        self::assertSame(['.ru'], $processed['registration']['blocked_email_domains']);
        self::assertSame([
            'casino',
            'crypto',
            'loan',
            'viagra',
            'seo',
            'backlink',
            'guest post',
            'cialis',
            'forex',
            'nft',
            'weight loss',
            'make money',
            'click here',
        ], $processed['feedback']['spam']['keywords']);
    }

    public function testRegistrationBlockedEmailDomainsCanBeOverridden(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [[
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
            'registration' => [
                'blocked_email_domains' => ['mailinator.com', '.ru'],
            ],
        ]]);

        self::assertSame(['mailinator.com', '.ru'], $processed['registration']['blocked_email_domains']);
    }

    public function testRegistrationBlockedEmailDomainsCanBeCleared(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [[
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
            'registration' => [
                'blocked_email_domains' => [],
            ],
        ]]);

        self::assertSame([], $processed['registration']['blocked_email_domains']);
    }

    public function testJobRunThresholdsDefaultToUnchecked(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [[
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
            'job_runs' => [
                'closure_rebuild' => ['slow_seconds' => 1800, 'memory_mb' => 200],
            ],
        ]]);

        self::assertSame(
            ['closure_rebuild' => ['slow_seconds' => 1800, 'memory_mb' => 200, 'long_seconds' => null, 'overdue_after_minutes' => null]],
            $processed['job_runs'],
        );
    }

    public function testStorageDefaultsAreApplied(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$this->minimalConfig()]);

        self::assertSame(
            ['notice_percent' => 80, 'warning_percent' => 90, 'critical_percent' => 99],
            $processed['storage']['thresholds'],
        );
        self::assertSame(36, $processed['storage']['measurement_max_age_hours']);
    }

    public function testStorageThresholdsMustIncrease(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Storage thresholds must increase strictly');

        new Processor()->processConfiguration(new AppConfiguration(), [[
            ...$this->minimalConfig(),
            'storage' => ['thresholds' => ['notice_percent' => 90, 'warning_percent' => 90, 'critical_percent' => 99]],
        ]]);
    }

    public function testStorageThresholdsStayBelowHundred(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new AppConfiguration(), [[
            ...$this->minimalConfig(),
            'storage' => ['thresholds' => ['critical_percent' => 100]],
        ]]);
    }

    /**
     * @return array{title: string, short_title: string, default_locale: string}
     */
    private function minimalConfig(): array
    {
        return [
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
        ];
    }
}
