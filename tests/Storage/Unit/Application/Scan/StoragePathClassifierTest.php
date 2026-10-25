<?php

declare(strict_types=1);

namespace App\Tests\Storage\Unit\Application\Scan;

use App\Storage\Application\Scan\StoragePathClassifier;
use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoragePathClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{StorageLocation, string, StorageCategory, string}>
     */
    public static function paths(): iterable
    {
        yield 'release root' => [StorageLocation::CurrentRelease, '', StorageCategory::Application, 'source'];
        yield 'source file' => [StorageLocation::CurrentRelease, 'src/Kernel.php', StorageCategory::Application, 'source'];
        yield 'symfony cache' => [StorageLocation::PreviousRelease, 'var/cache/prod/container.php', StorageCategory::Temporary, 'var_cache'];
        yield 'logs' => [StorageLocation::Project, 'var/log/prod.log', StorageCategory::Temporary, 'var_log'];
        yield 'import files' => [StorageLocation::Shared, 'var/imports/2026/a.csv', StorageCategory::Imports, 'var_imports'];
        yield 'import rejects' => [StorageLocation::Project, 'var/import_rejects/1.csv', StorageCategory::Imports, 'import_rejects'];
        yield 'media' => [StorageLocation::Shared, 'public/uploads/media/a.jpg', StorageCategory::Media, 'media_uploads'];
        yield 'geodata' => [StorageLocation::Shared, 'var/geo/hospital-isochrones/1.json', StorageCategory::Other, 'geodata'];
        yield 'other var' => [StorageLocation::Project, 'var/sessions/x', StorageCategory::Temporary, 'var_other'];
        yield 'var itself' => [StorageLocation::Project, 'var', StorageCategory::Temporary, 'var_other'];
        yield 'prefix is not a parent' => [StorageLocation::Project, 'var/imports-old/a.csv', StorageCategory::Temporary, 'var_other'];
        yield 'vendor' => [StorageLocation::CurrentRelease, 'vendor/symfony/x.php', StorageCategory::Application, 'vendor'];
        yield 'node modules' => [StorageLocation::Project, 'node_modules/x/index.js', StorageCategory::Application, 'node_modules'];
        yield 'built assets' => [StorageLocation::CurrentRelease, 'public/build/app.js', StorageCategory::Application, 'assets'];
        yield 'compiled asset map' => [StorageLocation::CurrentRelease, 'public/assets/app-1a2b.js', StorageCategory::Application, 'assets'];
        yield 'git' => [StorageLocation::Project, '.git/objects/ab', StorageCategory::Application, 'git'];
        yield 'local env' => [StorageLocation::Shared, '.env.local', StorageCategory::Other, 'env_local'];
        yield 'unmatched shared path' => [StorageLocation::Shared, 'old-stuff/file', StorageCategory::Other, 'shared_other'];
        yield 'deployer metadata' => [StorageLocation::DeployRoot, '.dep/releases_log', StorageCategory::Other, 'deploy_metadata'];
        yield 'deploy root rest' => [StorageLocation::DeployRoot, 'notes.txt', StorageCategory::Other, 'unclassified'];
        yield 'vendor in deploy root is not a release' => [StorageLocation::DeployRoot, 'vendor/x', StorageCategory::Other, 'unclassified'];
        yield 'database' => [StorageLocation::Database, '', StorageCategory::Database, 'database'];
    }

    #[DataProvider('paths')]
    public function testClassifiesPaths(StorageLocation $location, string $path, StorageCategory $category, string $contributor): void
    {
        $classification = new StoragePathClassifier()->classify($location, $path);

        self::assertSame($category, $classification->category);
        self::assertSame($contributor, $classification->contributor);
    }
}
