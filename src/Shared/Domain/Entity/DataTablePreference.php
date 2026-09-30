<?php

declare(strict_types=1);

namespace App\Shared\Domain\Entity;

use App\Shared\Infrastructure\Repository\DataTablePreferenceRepository;
use App\User\Domain\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DataTablePreferenceRepository::class)]
#[ORM\Table(name: 'data_table_preference')]
#[ORM\UniqueConstraint(name: 'uniq_data_table_preference_user_key', columns: ['user_id', 'table_key'])]
class DataTablePreference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'table_key', length: 120)]
    private string $tableKey;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $configuration = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(User $user, string $tableKey, array $configuration)
    {
        $this->user = $user;
        $this->tableKey = $tableKey;
        $this->configuration = $configuration;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTableKey(): string
    {
        return $this->tableKey;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguration(): array
    {
        return $this->configuration;
    }

    /**
     * @param array<string, mixed> $configuration
     */
    public function update(array $configuration): void
    {
        $this->configuration = $configuration;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
