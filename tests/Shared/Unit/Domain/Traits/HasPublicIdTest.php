<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Domain\Traits;

use App\Shared\Domain\Traits\HasPublicId;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class HasPublicIdTest extends TestCase
{
    public function testEnsurePublicIdGeneratesUuidV4(): void
    {
        $entity = new class {
            use HasPublicId {
                ensurePublicId as public;
            }
        };

        $entity->ensurePublicId();

        self::assertInstanceOf(Uuid::class, $entity->getPublicId());
        self::assertSame(36, \strlen($entity->getPublicIdString()));
        self::assertTrue(Uuid::isValid($entity->getPublicIdString()));
    }

    public function testExistingPublicIdIsNotOverwritten(): void
    {
        $entity = new class {
            use HasPublicId {
                ensurePublicId as public;
            }
        };

        $existing = Uuid::fromString('550e8400-e29b-41d4-a716-446655440000');
        $entity->setPublicId($existing);
        $entity->ensurePublicId();

        self::assertTrue($existing->equals($entity->getPublicId()));
    }

    public function testGetPublicIdStringThrowsWhenMissing(): void
    {
        $entity = new class {
            use HasPublicId;
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('missing publicId');

        $entity->getPublicIdString();
    }
}
