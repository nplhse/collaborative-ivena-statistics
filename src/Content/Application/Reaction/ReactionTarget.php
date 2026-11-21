<?php

declare(strict_types=1);

namespace App\Content\Application\Reaction;

use App\Content\Domain\Enum\ReactionTargetType;

/** A piece of content that can receive reactions, written as "<type>:<id>" in URLs (#467). */
final readonly class ReactionTarget
{
    public function __construct(
        public ReactionTargetType $type,
        public int $id,
    ) {
    }

    public static function fromString(string $value): ?self
    {
        if (1 !== preg_match('/^([a-z_]+):([1-9]\d{0,17})$/', trim($value), $matches)) {
            return null;
        }

        $type = ReactionTargetType::tryFrom($matches[1]);

        return $type instanceof ReactionTargetType ? new self($type, (int) $matches[2]) : null;
    }

    /**
     * Parses a comma-separated list, drops invalid entries and duplicates.
     *
     * @return list<self>
     */
    public static function listFromString(string $value, int $limit): array
    {
        $targets = [];
        foreach (explode(',', $value) as $part) {
            $target = self::fromString($part);
            if ($target instanceof self) {
                $targets[$target->key()] = $target;
            }
            if (\count($targets) >= $limit) {
                break;
            }
        }

        return array_values($targets);
    }

    public function key(): string
    {
        return $this->type->value.':'.$this->id;
    }

    /**
     * Id of the element that holds the reaction bar of this target.
     *
     * @psalm-suppress PossiblyUnusedMethod Read by the reaction templates.
     */
    public function domId(): string
    {
        return 'reactions-'.$this->type->value.'-'.$this->id;
    }
}
