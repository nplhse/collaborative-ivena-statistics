<?php

declare(strict_types=1);

namespace App\Tests\Install\Unit\Environment;

use App\Install\Application\Environment\EnvironmentChecker;
use App\Install\Application\Environment\EnvironmentCheckItem;
use App\Install\Application\Environment\EnvironmentCheckProfile;
use App\Install\Application\Environment\EnvironmentCheckReport;
use App\Install\Application\Environment\EnvironmentCheckStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnvironmentCheckerTest extends TestCase
{
    private EnvironmentChecker $checker;

    protected function setUp(): void
    {
        $this->checker = new EnvironmentChecker();
    }

    public function testProdProfileFailsWhenAppSecretMissing(): void
    {
        $report = $this->checker->check(
            EnvironmentCheckProfile::Prod,
            $this->validProdVariables(['APP_SECRET' => '']),
            true,
        );

        self::assertTrue($report->hasFailures());
        self::assertSame(
            EnvironmentCheckStatus::Fail,
            $this->statusFor($report, 'APP_SECRET'),
        );
    }

    public function testProdProfileFailsWhenAppUrlUsesLocalhost(): void
    {
        $report = $this->checker->check(
            EnvironmentCheckProfile::Prod,
            $this->validProdVariables(['APP_URL' => 'http://127.0.0.1:8000']),
            true,
        );

        self::assertTrue($report->hasFailures());
        self::assertSame(
            EnvironmentCheckStatus::Fail,
            $this->statusFor($report, 'APP_URL'),
        );
    }

    public function testProdProfileWarnsWhenSentryDsnMissing(): void
    {
        $report = $this->checker->check(
            EnvironmentCheckProfile::Prod,
            $this->validProdVariables(['SENTRY_DSN' => '']),
            true,
        );

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
        self::assertSame(
            EnvironmentCheckStatus::Warn,
            $this->statusFor($report, 'SENTRY_DSN'),
        );
    }

    public function testBetaProfileFailsWhenSentryDsnMissing(): void
    {
        $report = $this->checker->check(
            EnvironmentCheckProfile::Beta,
            $this->validProdVariables(['SENTRY_DSN' => '']),
            true,
        );

        self::assertTrue($report->hasFailures());
        self::assertSame(
            EnvironmentCheckStatus::Fail,
            $this->statusFor($report, 'SENTRY_DSN'),
        );
    }

    /**
     * @param array<string, string> $variables
     */
    #[DataProvider('devProfileProvider')]
    public function testDevProfileTreatsMissingProductionValuesAsWarnings(array $variables): void
    {
        $report = $this->checker->check(
            EnvironmentCheckProfile::Dev,
            $variables,
            true,
        );

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function devProfileProvider(): iterable
    {
        yield 'local defaults' => [[
            'APP_ENV' => 'dev',
            'APP_DEBUG' => '1',
            'APP_SECRET' => '',
            'DATABASE_URL' => 'postgresql://app:password@127.0.0.1:5432/app',
            'APP_URL' => 'http://127.0.0.1:8000',
            'MAILER_DSN' => 'null://null',
            'MAILER_FROM' => 'no-reply@localhost',
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?auto_setup=0',
            'SENTRY_DSN' => '',
            'SENTRY_ENVIRONMENT' => '',
        ]];
    }

    public function testValidProdProfilePassesWithoutDatabaseCheck(): void
    {
        $report = $this->checker->check(
            EnvironmentCheckProfile::Prod,
            $this->validProdVariables(),
            true,
        );

        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
    }

    public function testBetaProfilePassesWithFullConfiguration(): void
    {
        $report = $this->checker->check(
            EnvironmentCheckProfile::Beta,
            $this->validProdVariables(['SENTRY_ENVIRONMENT' => 'beta']),
            true,
        );

        self::assertFalse($report->hasFailures());
        self::assertFalse($report->hasWarnings());
    }

    public function testReportDetectsWarningsAndFailures(): void
    {
        $report = new EnvironmentCheckReport([
            new EnvironmentCheckItem('APP_ENV', EnvironmentCheckStatus::Ok, 'ok'),
            new EnvironmentCheckItem('SENTRY_DSN', EnvironmentCheckStatus::Warn, 'warn'),
        ]);

        self::assertFalse($report->hasFailures());
        self::assertTrue($report->hasWarnings());

        $failed = new EnvironmentCheckReport([
            new EnvironmentCheckItem('APP_SECRET', EnvironmentCheckStatus::Fail, 'fail'),
        ]);

        self::assertTrue($failed->hasFailures());
        self::assertFalse($failed->hasWarnings());
    }

    /**
     * @param array<string, string> $overrides
     *
     * @return array<string, string>
     */
    private function validProdVariables(array $overrides = []): array
    {
        return array_merge([
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'APP_SECRET' => bin2hex(random_bytes(16)),
            'DATABASE_URL' => 'postgresql://app:password@db.example.test:5432/app',
            'APP_URL' => 'https://coishub.example.test',
            'MAILER_DSN' => 'smtp://user:pass@smtp.example.test:587',
            'MAILER_FROM' => 'no-reply@example.test',
            'MESSENGER_TRANSPORT_DSN' => 'doctrine://default?auto_setup=0',
            'SENTRY_DSN' => 'https://example@sentry.example.test/1',
            'SENTRY_ENVIRONMENT' => 'prod',
        ], $overrides);
    }

    private function statusFor(EnvironmentCheckReport $report, string $variable): EnvironmentCheckStatus
    {
        foreach ($report->items as $item) {
            if ($item->variable === $variable) {
                return $item->status;
            }
        }

        self::fail(sprintf('Missing check result for %s', $variable));
    }
}
