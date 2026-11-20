<?php

declare(strict_types=1);

namespace App\Tests\Storage\Integration\Infrastructure\Backup;

use App\Storage\Application\Contract\MigrationCatalogInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DoctrineMigrationCatalogTest extends KernelTestCase
{
    public function testListsTheMigrationsOfTheMigrationsDirectory(): void
    {
        self::bootKernel();
        $available = self::getContainer()->get(MigrationCatalogInterface::class)->available();

        $files = glob(\dirname(__DIR__, 5).'/migrations/Version*.php');
        self::assertIsArray($files);
        self::assertNotSame([], $files);

        $newest = 'DoctrineMigrations\\'.basename((string) end($files), '.php');
        self::assertContains($newest, $available);
        self::assertGreaterThanOrEqual(\count($files), \count($available));
    }
}
