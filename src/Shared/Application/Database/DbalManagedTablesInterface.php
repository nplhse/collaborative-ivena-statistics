<?php

declare(strict_types=1);

namespace App\Shared\Application\Database;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Tables a context creates in its migrations and reads and writes with DBAL only, without a Doctrine entity
 * (#1372). The schema filter hides them from `doctrine:migrations:diff` and `doctrine:schema:update`, so a
 * generated migration does not drop them.
 *
 * Never list a table an entity maps: the filter would hide changes of its mapping from generated migrations.
 */
#[AutoconfigureTag(self::TAG)]
interface DbalManagedTablesInterface
{
    public const string TAG = 'app.dbal_managed_tables';

    /**
     * @return list<string> table names without schema
     */
    public function tables(): array;
}
