<?php

declare(strict_types=1);

namespace App\Import\UI\Http\DTO;

use App\Import\Domain\Enum\ImportStatus;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ListImportQueryParametersDTO
{
    public const string DEFAULT_CREATED_FROM = '2017-01-01';

    public ?string $search;

    #[Assert\When(
        expression: 'this.hospitalId !== null',
        constraints: [new Assert\GreaterThan(0)],
    )]
    public ?int $hospitalId;

    #[Assert\When(
        expression: 'this.ownerId !== null',
        constraints: [new Assert\GreaterThan(0)],
    )]
    public ?int $ownerId;

    #[Assert\When(
        expression: 'this.status !== null',
        constraints: [new Assert\Choice(callback: [ImportStatus::class, 'getValues'])],
    )]
    public ?string $status;

    #[Assert\When(
        expression: 'this.createdFrom !== null',
        constraints: [new Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/')],
    )]
    public ?string $createdFrom;

    #[Assert\When(
        expression: 'this.createdUntil !== null',
        constraints: [new Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/')],
    )]
    public ?string $createdUntil;

    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = 'desc',

        #[Assert\Choice(choices: ['id', 'type', 'name', 'status', 'hospital', 'lastChange', 'createdAt'])]
        public string $sortBy = 'createdAt',

        ?string $search = null,

        int|string|null $hospitalId = null,

        int|string|null $ownerId = null,

        ?string $status = null,

        ?string $createdFrom = null,

        ?string $createdUntil = null,
    ) {
        $this->search = $this->emptyStringToNull($search);
        $this->hospitalId = $this->normalizePositiveInt($hospitalId);
        $this->ownerId = $this->normalizePositiveInt($ownerId);
        $this->status = $this->emptyStringToNull($status);
        $this->createdFrom = $this->emptyStringToNull($createdFrom) ?? self::DEFAULT_CREATED_FROM;
        $this->createdUntil = $this->emptyStringToNull($createdUntil) ?? self::defaultCreatedUntil();
    }

    public static function defaultCreatedUntil(): string
    {
        return new \DateTimeImmutable('today')->format('Y-m-d');
    }

    public function isDefaultCreatedFrom(): bool
    {
        return self::DEFAULT_CREATED_FROM === $this->createdFrom;
    }

    public function isDefaultCreatedUntil(): bool
    {
        return self::defaultCreatedUntil() === $this->createdUntil;
    }

    private function emptyStringToNull(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return $value;
    }

    private function normalizePositiveInt(int|string|null $value): ?int
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
