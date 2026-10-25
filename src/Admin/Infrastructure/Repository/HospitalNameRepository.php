<?php

declare(strict_types=1);

namespace App\Admin\Infrastructure\Repository;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hospital names by id for admin lists that store hospital ids without an association (e.g. job_run).
 * Read once per request, so a list page needs one query instead of one per row.
 */
final class HospitalNameRepository implements ResetInterface
{
    /** @var array<int, string>|null */
    private ?array $names = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, string> id => name, ordered by name
     */
    public function all(): array
    {
        if (null === $this->names) {
            $names = [];
            /** @var array<int|string, string|null> $rows */
            $rows = $this->connection->fetchAllKeyValue('SELECT id, name FROM hospital ORDER BY name, id');
            foreach ($rows as $id => $name) {
                $names[(int) $id] = (string) $name;
            }
            $this->names = $names;
        }

        return $this->names;
    }

    public function nameOf(int $id): ?string
    {
        return $this->all()[$id] ?? null;
    }

    #[\Override]
    public function reset(): void
    {
        $this->names = null;
    }
}
