<?php

declare(strict_types=1);

namespace App\Allocation\Application\Filter;

use Doctrine\ORM\QueryBuilder;

/**
 * Query semantics for optional relations: unset vs explicit absence vs presence vs a concrete value.
 */
final readonly class OptionalRelationFilter
{
    public const string NONE = 'none';
    public const string ANY = 'any';

    private function __construct(
        public OptionalRelationFilterState $state,
        public ?int $equalsId = null,
    ) {
    }

    public static function unset(): self
    {
        return new self(OptionalRelationFilterState::Unset);
    }

    public static function fromQuery(?string $raw, bool $allowPresent = true): self
    {
        $trimmed = null === $raw ? '' : trim($raw);
        if ('' === $trimmed) {
            return self::unset();
        }

        if (self::NONE === $trimmed) {
            return new self(OptionalRelationFilterState::Absent);
        }

        if (self::ANY === $trimmed) {
            return $allowPresent
                ? new self(OptionalRelationFilterState::Present)
                : self::unset();
        }

        if (ctype_digit($trimmed) && (int) $trimmed > 0) {
            return new self(OptionalRelationFilterState::Equals, (int) $trimmed);
        }

        return self::unset();
    }

    /**
     * @psalm-api Used by callers that combine a presence flag with an optional concrete id.
     */
    public static function fromPresenceAndValue(?bool $present, ?int $id): self
    {
        if (null !== $id && $id > 0) {
            return new self(OptionalRelationFilterState::Equals, $id);
        }

        if (true === $present) {
            return new self(OptionalRelationFilterState::Present);
        }

        if (false === $present) {
            return new self(OptionalRelationFilterState::Absent);
        }

        return self::unset();
    }

    public function isActive(): bool
    {
        return OptionalRelationFilterState::Unset !== $this->state;
    }

    /**
     * @return array{0: ?int, 1: ?int} Presence flag (0/1) and optional concrete id
     */
    public function toPresenceAndId(): array
    {
        return match ($this->state) {
            OptionalRelationFilterState::Unset => [null, null],
            OptionalRelationFilterState::Absent => [0, null],
            OptionalRelationFilterState::Present => [1, null],
            OptionalRelationFilterState::Equals => [1, $this->equalsId],
        };
    }

    public function apply(
        QueryBuilder $qb,
        string $nullExpression,
        string $notNullExpression,
        string $valueExpression,
        string $paramName,
    ): void {
        if (OptionalRelationFilterState::Unset === $this->state) {
            return;
        }

        if (OptionalRelationFilterState::Absent === $this->state) {
            $qb->andWhere($nullExpression);

            return;
        }

        if (OptionalRelationFilterState::Present === $this->state) {
            $qb->andWhere($notNullExpression);

            return;
        }

        $qb->andWhere($valueExpression.' = :'.$paramName)
            ->setParameter($paramName, $this->equalsId);
    }
}
