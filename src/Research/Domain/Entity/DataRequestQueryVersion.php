<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One version of an export query (ADR 045): the SQL with its SHA-256, the result of the allowlist check and of the
 * last dry run. The SQL never changes; a change is a new version.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_query_version')]
#[ORM\UniqueConstraint(name: 'uniq_data_request_query_version_number', columns: ['query_id', 'number'])]
class DataRequestQueryVersion
{
    public const int MAX_SQL_LENGTH = 20000;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'versions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequestQuery $query;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $number;

    #[ORM\Column(type: Types::TEXT)]
    private string $sql;

    /** SHA-256 of the SQL; an export records it to show exactly which text ran. */
    #[ORM\Column(length: 64)]
    private string $checksum;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $checkedAt = null;

    /** @var list<array{code: string, parameters: array<string, string>}> */
    #[ORM\Column(type: Types::JSON)]
    private array $violations = [];

    /** @var array<string, mixed>|null summary of the last dry run */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dryRun = null;

    /**
     * @throws DataRequestException when the SQL is empty or too long
     */
    public function __construct(DataRequestQuery $query, int $number, string $sql, ?User $createdBy, \DateTimeImmutable $at)
    {
        $sql = trim($sql);
        if ('' === $sql || mb_strlen($sql) > self::MAX_SQL_LENGTH) {
            throw new DataRequestException('A query needs between 1 and 20000 characters of SQL.');
        }

        $this->query = $query;
        $this->number = $number;
        $this->sql = $sql;
        $this->checksum = hash('sha256', $sql);
        $this->createdBy = $createdBy;
        $this->createdAt = $at;
    }

    /**
     * @param list<array{code: string, parameters: array<string, string>}> $violations
     */
    public function recordCheck(array $violations, \DateTimeImmutable $at): void
    {
        $this->violations = $violations;
        $this->checkedAt = $at;
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function recordDryRun(array $summary): void
    {
        $this->dryRun = $summary;
    }

    /** Checked and without violations. */
    public function passesCheck(): bool
    {
        return $this->checkedAt instanceof \DateTimeImmutable && [] === $this->violations;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getQuery(): DataRequestQuery
    {
        return $this->query;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getSql(): string
    {
        return $this->sql;
    }

    public function getChecksum(): string
    {
        return $this->checksum;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCheckedAt(): ?\DateTimeImmutable
    {
        return $this->checkedAt;
    }

    /** @return list<array{code: string, parameters: array<string, string>}> */
    public function getViolations(): array
    {
        return $this->violations;
    }

    /** @return array<string, mixed>|null */
    public function getDryRun(): ?array
    {
        return $this->dryRun;
    }
}
