<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Domain\Entity;

use App\Content\Domain\Entity\Reaction;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class ReactionTest extends TestCase
{
    public function testANewReactionWasUpdatedWhenItWasCreated(): void
    {
        $user = new User();
        $createdAt = new \DateTimeImmutable('2026-11-21 10:00:00');

        $reaction = new Reaction($user, ReactionTargetType::Comment, 42, ReactionType::Like, $createdAt);

        self::assertNull($reaction->getId());
        self::assertSame($user, $reaction->getUser());
        self::assertSame(ReactionTargetType::Comment, $reaction->getTargetType());
        self::assertSame(42, $reaction->getTargetId());
        self::assertSame(ReactionType::Like, $reaction->getType());
        self::assertSame($createdAt, $reaction->getCreatedAt());
        self::assertSame($createdAt, $reaction->getUpdatedAt());
    }

    public function testChangingTheReactionKeepsTargetAndCreationTime(): void
    {
        $createdAt = new \DateTimeImmutable('2026-11-21 10:00:00');
        $changedAt = new \DateTimeImmutable('2026-11-21 11:30:00');
        $reaction = new Reaction(new User(), ReactionTargetType::Post, 7, ReactionType::Like, $createdAt);

        $reaction->change(ReactionType::Rocket, $changedAt);

        self::assertSame(ReactionType::Rocket, $reaction->getType());
        self::assertSame($createdAt, $reaction->getCreatedAt());
        self::assertSame($changedAt, $reaction->getUpdatedAt());
        self::assertSame(ReactionTargetType::Post, $reaction->getTargetType());
        self::assertSame(7, $reaction->getTargetId());
    }
}
