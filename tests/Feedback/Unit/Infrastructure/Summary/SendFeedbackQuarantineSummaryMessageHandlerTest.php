<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Infrastructure\Summary;

use App\Feedback\Application\Contract\FeedbackQuarantineSummaryInterface;
use App\Feedback\Application\Message\SendFeedbackQuarantineSummaryMessage;
use App\Feedback\Infrastructure\Summary\SendFeedbackQuarantineSummaryMessageHandler;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use PHPUnit\Framework\TestCase;

final class SendFeedbackQuarantineSummaryMessageHandlerTest extends TestCase
{
    public function testRecordsTheNumberOfReportedEntries(): void
    {
        $jobRun = $this->createMock(CurrentJobRunInterface::class);
        $jobRun->expects(self::once())->method('addMetrics')->with(['reported' => 3]);
        $jobRun->expects(self::never())->method('markSkipped');

        new SendFeedbackQuarantineSummaryMessageHandler($this->summary(3), $jobRun)(new SendFeedbackQuarantineSummaryMessage());
    }

    public function testAnEmptyRunIsSkipped(): void
    {
        $jobRun = $this->createMock(CurrentJobRunInterface::class);
        $jobRun->expects(self::once())->method('markSkipped')->with('nothing_to_report');
        $jobRun->expects(self::never())->method('addMetrics');

        new SendFeedbackQuarantineSummaryMessageHandler($this->summary(0), $jobRun)(new SendFeedbackQuarantineSummaryMessage());
    }

    private function summary(int $reported): FeedbackQuarantineSummaryInterface
    {
        $summary = $this->createStub(FeedbackQuarantineSummaryInterface::class);
        $summary->method('send')->willReturn($reported);

        return $summary;
    }
}
