<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\DependencyInjection;

use App\Shared\Infrastructure\DependencyInjection\AppConfiguration;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testMonitoringRetentionDefaultsAreApplied(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$this->minimalConfig()]);

        self::assertSame(['hourly_days' => 90, 'failure_days' => 30, 'queue_sample_days' => 90], $processed['messenger_monitoring']['retention']);
        self::assertSame(['routine_days' => 180, 'other_days' => 730], $processed['job_runs_retention']);
    }

    public function testMessengerHealthDefaultsAreAppliedAndTheBacklogLimitsMustIncrease(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$this->minimalConfig()]);

        self::assertSame(
            ['backlog_warning_minutes' => 30, 'backlog_critical_minutes' => 120, 'stalled_after_minutes' => 15, 'retry_rate_warning_percent' => 10, 'retry_min_attempts' => 20],
            $processed['messenger_monitoring']['health'],
        );

        $this->expectException(InvalidConfigurationException::class);
        new Processor()->processConfiguration(new AppConfiguration(), [$this->minimalConfig() + ['messenger_monitoring' => ['health' => ['backlog_warning_minutes' => 120, 'backlog_critical_minutes' => 60]]]]);
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

    public function testDataPrepToolVersionDefaults(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [[
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
        ]]);

        self::assertSame(['version' => '1.0.0', 'min_version' => '1.0.0', 'released_at' => '2026-11-12'], $processed['encounter']['data_prep']);
    }

    public function testEncounterSourceFileRetentionDefaultsToThirtyDays(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$this->minimalConfig()]);

        self::assertSame(30, $processed['encounter']['source_file_retention_days']);
    }

    public function testEncounterValidationDefaults(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$this->minimalConfig()]);

        self::assertSame(['score_bands' => [0.50, 0.70, 0.90, 0.99], 'sample_size' => 20], $processed['encounter']['validation']);
    }

    public function testEncounterPlausibilityMinimumObservationsDefaultsToTenAndIsPositive(): void
    {
        $processed = new Processor()->processConfiguration(new AppConfiguration(), [$this->minimalConfig()]);
        self::assertSame(['min_observations' => 10], $processed['encounter']['plausibility']);

        $this->expectException(InvalidConfigurationException::class);
        new Processor()->processConfiguration(new AppConfiguration(), [[
            ...$this->minimalConfig(),
            'encounter' => ['plausibility' => ['min_observations' => 0]],
        ]]);
    }

    public function testEncounterValidationScoreBandsMustAscend(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new AppConfiguration(), [[
            ...$this->minimalConfig(),
            'encounter' => ['validation' => ['score_bands' => [0.90, 0.50]]],
        ]]);
    }

    #[DataProvider('invalidEncounterSourceFileRetentionDays')]
    public function testEncounterSourceFileRetentionMustBeAPositiveInteger(mixed $days): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new AppConfiguration(), [[
            ...$this->minimalConfig(),
            'encounter' => ['source_file_retention_days' => $days],
        ]]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidEncounterSourceFileRetentionDays(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'string' => ['30'];
        yield 'float' => [30.5];
    }

    /**
     * @param array<string, string> $dataPrep
     */
    #[DataProvider('invalidDataPrepVersions')]
    public function testDataPrepToolVersionIsValidated(array $dataPrep): void
    {
        $this->expectException(InvalidConfigurationException::class);

        new Processor()->processConfiguration(new AppConfiguration(), [[
            'title' => 'App title',
            'short_title' => 'APP',
            'default_locale' => 'en',
            'encounter' => ['data_prep' => $dataPrep],
        ]]);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function invalidDataPrepVersions(): iterable
    {
        yield 'version without patch' => [['version' => '1.0']];
        yield 'minimum above version' => [['version' => '1.0.0', 'min_version' => '1.1.0']];
        yield 'release date format' => [['released_at' => '12.11.2026']];
    }
}
