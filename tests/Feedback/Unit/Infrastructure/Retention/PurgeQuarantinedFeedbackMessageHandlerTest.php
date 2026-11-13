<?php

declare(strict_types=1);

namespace App\Tests\Feedback\Unit\Infrastructure\Retention;

use App\Feedback\Application\Contract\FeedbackQuarantinePurgeInterface;
use App\Feedback\Application\Message\PurgeQuarantinedFeedbackMessage;
use App\Feedback\Infrastructure\Retention\PurgeQuarantinedFeedbackMessageHandler;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use PHPUnit\Framework\TestCase;

final class PurgeQuarantinedFeedbackMessageHandlerTest extends TestCase
{
    public function testRecordsTheNumberOfDeletedEntries(): void
    {
        $purge = $this->createStub(FeedbackQuarantinePurgeInterface::class);
        $purge->method('purge')->willReturn(4);
        $jobRun = $this->createMock(CurrentJobRunInterface::class);
        $jobRun->expects(self::once())->method('addMetrics')->with(['deleted' => 4]);

        new PurgeQuarantinedFeedbackMessageHandler($purge, $jobRun)(new PurgeQuarantinedFeedbackMessage());
    }
}
