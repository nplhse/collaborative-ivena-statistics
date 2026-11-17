<?php

declare(strict_types=1);

namespace App\DataFixtures\Purger;

/**
 * Lets a plugin take part in the purge of a fixture load (#1300): tables it maintains in the app and that the
 * fixtures keep, and work after the purge (for example data derived from the kept tables). Only loaded where the
 * fixtures bundle is installed (dev, test, demo). Tag the service with TAG yourself: src/DataFixtures is no
 * autoconfigured resource of the container, so an attribute here would not apply.
 */
interface FixturePurgeParticipantInterface
{
    public const string TAG = 'app.fixtures.purge_participant';

    /**
     * @return list<string> tables the purge leaves alone
     */
    public function keptTables(): array;

    /**
     * Runs after the purge, before the materialized views are installed again, in the executor's transaction.
     */
    public function afterPurge(): void;
}
