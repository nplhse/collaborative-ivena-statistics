<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Usernames are unique regardless of case (#1353): profile URLs `/explore/user/{username}` ignore the case.
 * The index exists only in migrations; the ORM mapping keeps the case-sensitive UNIQ_IDENTIFIER_USERNAME.
 */
final class Version20261119155253 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add a unique index on LOWER(username) to the user table.';
    }

    public function up(Schema $schema): void
    {
        $collisions = $this->connection->fetchFirstColumn(
            'SELECT LOWER(username) FROM "user" GROUP BY LOWER(username) HAVING COUNT(*) > 1 ORDER BY 1',
        );
        $this->abortIf(
            [] !== $collisions,
            \sprintf(
                'Usernames that differ only in case: %s. Rename all but one of each (EasyAdmin), then migrate again.',
                implode(', ', array_map(static fn (mixed $name): string => \is_string($name) ? $name : '', $collisions)),
            ),
        );

        $this->addSql('CREATE UNIQUE INDEX uniq_user_username_lower ON "user" (LOWER(username))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS uniq_user_username_lower');
    }
}
