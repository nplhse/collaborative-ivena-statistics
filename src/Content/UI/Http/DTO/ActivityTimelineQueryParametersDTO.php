<?php

declare(strict_types=1);

namespace App\Content\UI\Http\DTO;

use App\User\Application\Explore\ProjectActivityFilters;
use App\User\Application\Explore\ProjectActivityPage;
use App\User\Domain\Enum\UserActivityType;

final readonly class ActivityTimelineQueryParametersDTO
{
    public ?string $from;

    public ?string $until;

    public ?string $type;

    public ?string $user;

    public ?string $search;

    public ?string $cursor;

    public function __construct(
        ?string $from = null,
        ?string $until = null,
        ?string $type = null,
        ?string $user = null,
        ?string $search = null,
        ?string $cursor = null,
    ) {
        $this->from = $this->normalizeDate($from);
        $this->until = $this->normalizeDate($until);
        $this->type = $this->normalizeType($type);
        $this->user = $this->emptyStringToNull($user);
        $this->search = $this->emptyStringToNull($search);
        $this->cursor = $this->emptyStringToNull($cursor);
    }

    public function hasActiveFilters(): bool
    {
        return null !== $this->from
            || null !== $this->until
            || null !== $this->type
            || null !== $this->user
            || null !== $this->search;
    }

    /**
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        return array_filter(
            [
                'from' => $this->from,
                'until' => $this->until,
                'type' => $this->type,
                'user' => $this->user,
                'search' => $this->search,
            ],
            static fn (?string $value): bool => null !== $value && '' !== $value,
        );
    }

    public function toFilters(): ProjectActivityFilters
    {
        $type = null;
        if (null !== $this->type) {
            $type = UserActivityType::tryFrom($this->type);
        }

        return new ProjectActivityFilters(
            from: $this->dateStart($this->from),
            untilExclusive: $this->dateUntilExclusive($this->until),
            type: $type,
            username: $this->user,
            search: $this->search,
        );
    }

    private function normalizeDate(?string $value): ?string
    {
        $normalized = $this->emptyStringToNull($value);
        if (null === $normalized) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $normalized);
        if (false === $date || $date->format('Y-m-d') !== $normalized) {
            return null;
        }

        return $normalized;
    }

    private function normalizeType(?string $value): ?string
    {
        $normalized = $this->emptyStringToNull($value);
        if (null === $normalized) {
            return null;
        }

        $type = UserActivityType::tryFrom($normalized);
        if (!$type instanceof UserActivityType || !\in_array($type, ProjectActivityPage::feedTypes(), true)) {
            return null;
        }

        return $type->value;
    }

    private function dateStart(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if (false === $date) {
            return null;
        }

        return $date->setTime(0, 0, 0);
    }

    private function dateUntilExclusive(?string $value): ?\DateTimeImmutable
    {
        $start = $this->dateStart($value);
        if (!$start instanceof \DateTimeImmutable) {
            return null;
        }

        return $start->modify('+1 day');
    }

    private function emptyStringToNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
