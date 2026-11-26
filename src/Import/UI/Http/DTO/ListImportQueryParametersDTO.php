<?php

declare(strict_types=1);

namespace App\Import\UI\Http\DTO;

use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\UI\Twig\ImportListColumns;
use Symfony\Component\Clock\Clock;
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
        expression: 'this.type !== null',
        constraints: [new Assert\Choice(callback: [ImportType::class, 'getValues'])],
    )]
    public ?string $type;

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

    /** Imports whose data period (export period) reaches this day or later. */
    #[Assert\When(
        expression: 'this.periodFrom !== null',
        constraints: [new Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/')],
    )]
    public ?string $periodFrom;

    /** Imports whose data period (export period) starts on this day or earlier. */
    #[Assert\When(
        expression: 'this.periodUntil !== null',
        constraints: [new Assert\Regex(pattern: '/^\d{4}-\d{2}-\d{2}$/')],
    )]
    public ?string $periodUntil;

    /** Only the imports of this bulk reprocess batch. */
    #[Assert\When(
        expression: 'this.batch !== null',
        constraints: [new Assert\GreaterThan(0)],
    )]
    public ?int $batch;

    public function __construct(
        #[Assert\GreaterThan(0)]
        public int $page = 1,

        #[Assert\Range(min: 1, max: 100)]
        public int $limit = 25,

        #[Assert\Choice(choices: ['asc', 'desc'])]
        public string $orderBy = ImportListColumns::DEFAULT_ORDER_BY,

        #[Assert\Choice(choices: ImportListColumns::SORT_KEYS)]
        public string $sortBy = ImportListColumns::DEFAULT_SORT_BY,

        ?string $search = null,

        int|string|null $hospitalId = null,

        int|string|null $ownerId = null,

        ?string $status = null,

        ?string $createdFrom = null,

        ?string $createdUntil = null,

        int|string|null $batch = null,

        ?string $type = null,

        ?string $periodFrom = null,

        ?string $periodUntil = null,
    ) {
        $this->search = $this->emptyStringToNull($search);
        $this->hospitalId = $this->normalizePositiveInt($hospitalId);
        $this->ownerId = $this->normalizePositiveInt($ownerId);
        $this->status = $this->emptyStringToNull($status);
        $this->createdFrom = $this->emptyStringToNull($createdFrom) ?? self::DEFAULT_CREATED_FROM;
        $this->createdUntil = $this->emptyStringToNull($createdUntil) ?? self::defaultCreatedUntil();
        $this->batch = $this->normalizePositiveInt($batch);
        $this->type = $this->emptyStringToNull($type);
        $this->periodFrom = $this->emptyStringToNull($periodFrom);
        $this->periodUntil = $this->emptyStringToNull($periodUntil);
    }

    public static function defaultCreatedUntil(): string
    {
        return Clock::get()->now()->format('Y-m-d');
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
