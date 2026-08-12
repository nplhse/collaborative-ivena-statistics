<?php

declare(strict_types=1);

namespace App\User\Application\Explore;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UserQueryParameters
{
    public ?int $hospitalId;

    #[Assert\Choice(choices: ['asc', 'desc'])]
    public string $orderBy;

    #[Assert\Choice(choices: ['username', 'createdAt'])]
    public string $sortBy;

    /** @psalm-suppress PossiblyUnusedMethod Instantiated by Symfony MapQueryString. */
    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        string $orderBy = 'asc',

        string $sortBy = 'username',

        public ?string $search = null,

        int|string|null $hospitalId = null,

        public ?string $participant = null,

        public ?string $boardMember = null,
    ) {
        $this->hospitalId = $this->normalizePositiveInt($hospitalId);
        $this->orderBy = $this->normalizeOrderBy($orderBy);
        $this->sortBy = $this->normalizeSortBy($sortBy);
    }

    public function wantsParticipant(): bool
    {
        return $this->isTruthyFilter($this->participant);
    }

    public function wantsBoardMember(): bool
    {
        return $this->isTruthyFilter($this->boardMember);
    }

    private function isTruthyFilter(?string $value): bool
    {
        if (null === $value || '' === $value) {
            return false;
        }

        return \in_array(mb_strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizePositiveInt(int|string|null $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        if (\is_string($value) && !ctype_digit($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeOrderBy(string $orderBy): string
    {
        $normalized = mb_strtolower(trim($orderBy));

        return 'desc' === $normalized ? 'desc' : 'asc';
    }

    private function normalizeSortBy(string $sortBy): string
    {
        $normalized = mb_strtolower(trim($sortBy));

        return match ($normalized) {
            'createdat', 'created_at' => 'createdAt',
            default => 'username',
        };
    }
}
