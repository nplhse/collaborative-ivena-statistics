<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stores `public_id` as `uuid` on the 14 tables that still use VARCHAR(36) (#1398). The mapping (`HasPublicId`,
 * `HasRequiredPublicId`) and every newer table already use `uuid`. The unique indexes are recreated with the names the
 * mapping expects and without their `WHERE public_id IS NOT NULL` condition, which a unique index does not need
 * (NULL values never collide).
 *
 * Changing the type rewrites each table with all its indexes under an exclusive lock. On the production mirror of
 * 2026-11 this is `allocation` with about 2.35 million rows and 1.2 GB including indexes: the upgrade needs roughly
 * that much additional free disk space and runs in maintenance mode.
 *
 * The migration stops before changing anything while a value is not a lowercase RFC 4122 UUID (the application only
 * writes `Uuid::v4()`). down() converts back to VARCHAR(36) with the old indexes; the values stay the same.
 */
final class Version20261127115529 extends AbstractMigration
{
    /** Table => unique index name the mapping expects. */
    private const array TABLES = [
        'allocation' => 'UNIQ_5C44232AB5B48B91',
        'assignment' => 'UNIQ_30C544BAB5B48B91',
        'department' => 'UNIQ_CD1DE18AB5B48B91',
        'dispatch_area' => 'UNIQ_94247A15B5B48B91',
        'hospital' => 'UNIQ_4282C85BB5B48B91',
        'indication_group' => 'UNIQ_FE572C56B5B48B91',
        'indication_normalized' => 'UNIQ_B3197597B5B48B91',
        'indication_raw' => 'UNIQ_E928F552B5B48B91',
        'infection' => 'UNIQ_56518720B5B48B91',
        'mci_case' => 'UNIQ_58667CEBB5B48B91',
        'occasion' => 'UNIQ_8A66DCE5B5B48B91',
        'secondary_transport' => 'UNIQ_F98F6457B5B48B91',
        'speciality' => 'UNIQ_F3D7A08EB5B48B91',
        'state' => 'UNIQ_A393D2FBB5B48B91',
    ];

    private const string UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    public function getDescription(): string
    {
        return 'Store public_id as uuid instead of VARCHAR(36) on 14 tables.';
    }

    public function up(Schema $schema): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $invalid = (int) $this->connection->fetchOne(
                \sprintf('SELECT COUNT(*) FROM %s WHERE public_id IS NOT NULL AND public_id !~ :pattern', $table),
                ['pattern' => self::UUID_PATTERN],
            );
            $this->abortIf(
                $invalid > 0,
                \sprintf('%d row(s) of %s have a public_id that is not a lowercase UUID. Fix them before migrating.', $invalid, $table),
            );
        }

        foreach (self::TABLES as $table => $index) {
            // The type change rebuilds every index of the table; the unique index is created once afterwards instead.
            $this->addSql(\sprintf('DROP INDEX uniq_%s_public_id', $table));
            // The old columns carry DEFAULT NULL::character varying, which cannot be cast; NULL stays the default.
            $this->addSql(\sprintf('ALTER TABLE %s ALTER public_id DROP DEFAULT', $table));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER public_id TYPE UUID USING public_id::uuid', $table));
            $this->addSql(\sprintf('CREATE UNIQUE INDEX %s ON %s (public_id)', $index, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => $index) {
            $this->addSql(\sprintf('DROP INDEX %s', $index));
            $this->addSql(\sprintf('ALTER TABLE %s ALTER public_id TYPE VARCHAR(36) USING public_id::text', $table));
            $this->addSql(\sprintf('CREATE UNIQUE INDEX uniq_%1$s_public_id ON %1$s (public_id) WHERE (public_id IS NOT NULL)', $table));
        }
    }
}
