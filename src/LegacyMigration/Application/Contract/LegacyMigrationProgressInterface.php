<?php

declare(strict_types=1);

namespace App\LegacyMigration\Application\Contract;

interface LegacyMigrationProgressInterface
{
    public function startPhase(string $name, int $max): void;

    public function advance(int $step = 1): void;

    public function setMessage(string $message): void;

    public function finishPhase(): void;

    public function note(string $message): void;
}
