<?php

declare(strict_types=1);

namespace App\Tests\Content\Unit\Application\Reaction;

use App\Content\Application\Reaction\ReactionCount;
use App\Content\Application\Reaction\ReactionSummary;
use App\Content\Application\Reaction\ReactionTarget;
use App\Content\Domain\Enum\ReactionTargetType;
use App\Content\Domain\Enum\ReactionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReactionTargetTest extends TestCase
{
    public function testParsesTypeAndId(): void
    {
        $target = ReactionTarget::fromString('comment:42');

        self::assertNotNull($target);
        self::assertSame(ReactionTargetType::Comment, $target->type);
        self::assertSame(42, $target->id);
        self::assertSame('comment:42', $target->key());
        self::assertSame('reactions-comment-42', $target->domId());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'unknown type' => ['page:1'];
        yield 'zero id' => ['post:0'];
        yield 'negative id' => ['post:-3'];
        yield 'no id' => ['post:'];
        yield 'markup' => ['post:1<script>'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $value): void
    {
        self::assertNull(ReactionTarget::fromString($value));
    }

    public function testListDropsInvalidEntriesAndDuplicatesAndStopsAtTheLimit(): void
    {
        $targets = ReactionTarget::listFromString('post:1, comment:2,bogus,post:1,activity:3,post:4', 3);

        self::assertSame(['post:1', 'comment:2', 'activity:3'], array_map(static fn (ReactionTarget $target): string => $target->key(), $targets));
    }

    public function testSummaryFindsTheOwnReaction(): void
    {
        $summary = new ReactionSummary(new ReactionTarget(ReactionTargetType::Post, 1), [
            new ReactionCount(ReactionType::Like, 3, false, ['Anna', 'Ben', 'Cem']),
            new ReactionCount(ReactionType::Rocket, 2, true, ['Dana']),
        ]);

        self::assertSame(ReactionType::Rocket, $summary->own());
        self::assertSame(0, $summary->counts[0]->unnamed());
        self::assertSame(1, $summary->counts[1]->unnamed());
    }

    public function testCountWithoutNamesHasNoUnnamedRest(): void
    {
        self::assertSame(0, new ReactionCount(ReactionType::Like, 7, false)->unnamed());
    }

    public function testEveryReactionHasItsOwnEmoji(): void
    {
        $emojis = array_map(static fn (ReactionType $type): string => $type->emoji(), ReactionType::cases());

        self::assertCount(8, $emojis);
        self::assertSame($emojis, array_values(array_unique($emojis)));
        self::assertSame('reaction.type.insightful', ReactionType::Insightful->labelKey());
    }
}
