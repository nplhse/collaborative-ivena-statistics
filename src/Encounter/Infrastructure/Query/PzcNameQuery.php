<?php

declare(strict_types=1);

namespace App\Encounter\Infrastructure\Query;

use App\Encounter\Application\Contract\PzcNameReaderInterface;
use Doctrine\DBAL\Connection;

/**
 * Names of PZC codes from the normalized indications (`indication_normalized`) for the plausibility review pages.
 *
 * @psalm-suppress UnusedClass Wired as the only implementation of PzcNameReaderInterface.
 */
final readonly class PzcNameQuery implements PzcNameReaderInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[\Override]
    public function names(): array
    {
        $names = [];
        foreach ($this->connection->fetchAllAssociative('SELECT code, name FROM indication_normalized ORDER BY code, id') as $row) {
            $names[(int) $row['code']] ??= (string) $row['name'];
        }

        return $names;
    }
}
