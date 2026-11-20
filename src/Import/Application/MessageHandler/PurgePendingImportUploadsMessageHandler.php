<?php

declare(strict_types=1);

namespace App\Import\Application\MessageHandler;

use App\Import\Application\Message\PurgePendingImportUploadsMessage;
use App\Import\Application\Service\PendingImportUploadStore;
use App\Shared\Application\JobRun\CurrentJobRunInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Hourly scheduler job `import_pending_upload_purge` (ADR 019 job run log).
 *
 * @psalm-suppress UnusedClass Registered via #[AsMessageHandler].
 */
#[AsMessageHandler]
final readonly class PurgePendingImportUploadsMessageHandler
{
    public function __construct(
        private PendingImportUploadStore $store,
        private CurrentJobRunInterface $jobRun,
    ) {
    }

    /** @psalm-suppress UnusedParam The message type selects the handler; it carries no data. */
    public function __invoke(PurgePendingImportUploadsMessage $message): void
    {
        $this->jobRun->addMetrics(['deleted_files' => $this->store->purgeExpired()]);
    }
}
