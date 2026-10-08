<?php

declare(strict_types=1);

namespace App\Statistics\Application\MessageHandler;

use App\Statistics\Application\Contract\ClosureVolumeProjectionRebuildInterface;
use App\Statistics\Application\Message\RebuildClosureVolumeProjection;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RebuildClosureVolumeProjectionHandler
{
    public function __construct(private ClosureVolumeProjectionRebuildInterface $rebuilder)
    {
    }

    public function __invoke(RebuildClosureVolumeProjection $_message): void
    {
        $this->rebuilder->consume();
    }
}
