<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Application\PublicId;

use App\Shared\Application\PublicId\PublicIdBackfillInterruptedException;
use PHPUnit\Framework\TestCase;

final class PublicIdBackfillInterruptedExceptionTest extends TestCase
{
    public function testStoresSignal(): void
    {
        $exception = new PublicIdBackfillInterruptedException(\SIGTERM);

        self::assertSame(\SIGTERM, $exception->getSignal());
        self::assertStringContainsString('signal 15', $exception->getMessage());
    }
}
