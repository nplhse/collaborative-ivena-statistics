<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Domain\Traits;

use App\Shared\Domain\Traits\HasRequiredPublicId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HasRequiredPublicIdTest extends TestCase
{
    public function testAnEntityThatWasNeverStoredHasNoPublicId(): void
    {
        $entity = new class {
            use HasRequiredPublicId;
        };

        self::assertNull($entity->getPublicId());
    }

    public function testPersistCreatesAnIdWhenNoneWasSet(): void
    {
        $entity = new class {
            use HasRequiredPublicId;
        };

        $entity->ensurePublicId();

        self::assertTrue(Uuid::isValid($entity->getPublicIdString()));
    }

    public function testPersistKeepsAnIdThatWasSetBefore(): void
    {
        $entity = new class {
            use HasRequiredPublicId;
        };

        $given = Uuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        $entity->setPublicId($given);
        $entity->ensurePublicId();

        self::assertTrue($given->equals($entity->getPublicId()));
    }

    public function testPublicIdStringThrowsWhenMissing(): void
    {
        $entity = new class {
            use HasRequiredPublicId;
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing publicId');

        $entity->getPublicIdString();
    }
}
