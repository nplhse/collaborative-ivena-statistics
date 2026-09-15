<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure;

use App\Import\Domain\Entity\Import;
use App\Import\Infrastructure\ImportCreatedById;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class ImportCreatedByIdTest extends TestCase
{
    public function testCaptureFromStoresCreatedById(): void
    {
        $createdBy = $this->createStub(User::class);
        $createdBy->method('getId')->willReturn(7);
        $import = new Import()->setCreatedBy($createdBy);

        $holder = new ImportCreatedById();
        $holder->captureFrom($import);

        self::assertSame(7, $holder->userId());
    }

    public function testUserIdIsNullBeforeCapture(): void
    {
        self::assertNull(new ImportCreatedById()->userId());
    }

    public function testUserIdIsNullWhenImportHasNoCreator(): void
    {
        $holder = new ImportCreatedById();
        $holder->captureFrom(new Import());

        self::assertNull($holder->userId());
    }
}
