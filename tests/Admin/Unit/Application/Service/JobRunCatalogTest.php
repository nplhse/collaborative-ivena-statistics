<?php

declare(strict_types=1);

namespace App\Tests\Admin\Unit\Application\Service;

use App\Admin\Application\Service\JobRun\JobRunCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * job_run stores plain strings (ADR 018), so unknown values are shown as they are instead of failing.
 */
final class JobRunCatalogTest extends TestCase
{
    public function testEveryJobWithMetricsIsAKnownJob(): void
    {
        $unknownJobs = array_diff(array_keys(JobRunCatalog::METRICS), JobRunCatalog::JOBS);

        self::assertSame([], array_values($unknownJobs));
    }

    public function testAKnownMetricIsTranslatedAndAnUnknownOneShownAsItIs(): void
    {
        $known = JobRunCatalog::metricLabel('rows_total');
        $unknown = JobRunCatalog::metricLabel('extra_key');

        self::assertEquals(new TranslatableMessage('admin.job_run.metric.rows_total', domain: 'admin'), $known);
        self::assertSame('extra_key', $unknown);
    }

    public function testAnUnknownCurrentValueIsAddedToTheChoicesAsItIs(): void
    {
        $choices = JobRunCatalog::jobChoices('retired_job');

        self::assertSame('retired_job', $choices['retired_job']);
        self::assertCount(\count(JobRunCatalog::JOBS) + 1, $choices);
    }

    public function testAnEmptyCurrentValueAddsNoChoice(): void
    {
        $choices = JobRunCatalog::statusChoices('');

        self::assertCount(\count(JobRunCatalog::STATUSES), $choices);
    }

    public function testAnUnknownStatusGetsTheNeutralBadge(): void
    {
        $badge = JobRunCatalog::statusBadge('paused');

        self::assertSame('secondary', $badge);
    }

    public function testAJobWithoutKnownMetricsHasNoFormats(): void
    {
        $formats = JobRunCatalog::metricFormats('retired_job');

        self::assertSame([], $formats);
    }
}
