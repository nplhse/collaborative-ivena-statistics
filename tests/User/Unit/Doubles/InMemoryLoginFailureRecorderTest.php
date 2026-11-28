<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Doubles;

use App\Tests\User\Contract\LoginFailureRecorderContract;
use App\Tests\User\Doubles\InMemoryLoginFailureRecorder;
use App\User\Application\Contract\LoginFailureRecorderInterface;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class InMemoryLoginFailureRecorderTest extends LoginFailureRecorderContract
{
    private InMemoryLoginFailureRecorder $recorder;

    protected function setUp(): void
    {
        $this->recorder = new InMemoryLoginFailureRecorder();
    }

    #[\Override]
    protected function recorder(): LoginFailureRecorderInterface
    {
        return $this->recorder;
    }

    #[\Override]
    protected function recordedKinds(): array
    {
        return $this->recorder->kinds();
    }

    #[\Override]
    protected function recordedIdentifiers(): array
    {
        return array_column($this->recorder->records, 'identifier');
    }
}
