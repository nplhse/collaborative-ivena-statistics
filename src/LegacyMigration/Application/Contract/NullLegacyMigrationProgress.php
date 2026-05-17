<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Contract;

final class NullLegacyMigrationProgress implements LegacyMigrationProgressInterface
{
    #[\Override]
    public function startPhase(string $name, int $max): void
    {
    }

    #[\Override]
    public function advance(int $step = 1): void
    {
    }

    #[\Override]
    public function setMessage(string $message): void
    {
    }

    #[\Override]
    public function finishPhase(): void
    {
    }

    #[\Override]
    public function note(string $message): void
    {
    }
}
