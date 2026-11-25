<?php

declare(strict_types=1);

namespace App\Research\Domain\Entity;

use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Exception\DataRequestException;
use App\Shared\Infrastructure\Audit\Attribute as Audit;
use App\User\Domain\Entity\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One export query of an approved data extract request (ADR 045), written by an administrator. Its name becomes the
 * CSV file name; every change of the SQL is a new version, so it stays clear which SQL a release and an export used.
 */
#[Audit\Audited]
#[ORM\Entity]
#[ORM\Table(name: 'data_request_query')]
#[ORM\UniqueConstraint(name: 'uniq_data_request_query_name', columns: ['request_id', 'name'])]
class DataRequestQuery
{
    public const string NAME_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'queries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private DataRequest $request;

    #[ORM\Column(length: 64)]
    private string $name;

    #[ORM\Column(type: Types::TEXT)]
    private string $description;

    #[ORM\Column(length: 16, enumType: QueryKind::class)]
    private QueryKind $kind;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, DataRequestQueryVersion> */
    #[ORM\OneToMany(targetEntity: DataRequestQueryVersion::class, mappedBy: 'query', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['number' => 'ASC'])]
    private Collection $versions;

    /**
     * @throws DataRequestException when the name is not a lowercase identifier or the description is empty
     */
    public function __construct(DataRequest $request, string $name, string $description, QueryKind $kind, string $sql, ?User $author, \DateTimeImmutable $at)
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name)) {
            throw new DataRequestException('A query name is a lowercase identifier, for example "allocations_by_month".');
        }

        $this->request = $request;
        $this->name = $name;
        $this->kind = $kind;
        $this->createdAt = $at;
        $this->versions = new ArrayCollection();
        $this->describe($description);
        $this->revise($sql, $author, $at);
    }

    /**
     * @throws DataRequestException when the SQL is empty
     */
    public function revise(string $sql, ?User $author, \DateTimeImmutable $at): void
    {
        $number = $this->versions->isEmpty() ? 1 : $this->getCurrentVersion()->getNumber() + 1;
        $this->versions->add(new DataRequestQueryVersion($this, $number, $sql, $author, $at));
    }

    /**
     * @throws DataRequestException when the description is empty
     */
    public function describe(string $description): void
    {
        $description = trim($description);
        if ('' === $description) {
            throw new DataRequestException('A query needs a description in plain language.');
        }

        $this->description = $description;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): DataRequest
    {
        return $this->request;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getKind(): QueryKind
    {
        return $this->kind;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return list<DataRequestQueryVersion> */
    public function getVersions(): array
    {
        return array_values($this->versions->toArray());
    }

    public function getCurrentVersion(): DataRequestQueryVersion
    {
        $current = $this->versions->last();
        if (!$current instanceof DataRequestQueryVersion) {
            throw new \LogicException('A query always has a version.');
        }

        return $current;
    }
}
