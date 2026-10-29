<?php

declare(strict_types=1);

namespace App\Storage\Application\Scan;

use App\Storage\Domain\Enum\StorageCategory;
use App\Storage\Domain\Enum\StorageLocation;

/**
 * Assigns a path to a category and contributor by ordered prefix rules (ADR 017, "Usage metric").
 * Paths are relative to their location (release, shared directory, project or deploy root) and use "/".
 * Nothing is excluded: every file belongs to exactly one contributor.
 */
final class StoragePathClassifier
{
    /**
     * Rules for releases, the shared directory and standalone projects; first match wins.
     *
     * @var list<array{string, StorageCategory, string}>
     */
    private const array APPLICATION_RULES = [
        ['var/cache', StorageCategory::Temporary, 'var_cache'],
        ['var/log', StorageCategory::Temporary, 'var_log'],
        ['var/imports', StorageCategory::Imports, 'var_imports'],
        ['var/import_rejects', StorageCategory::Imports, 'import_rejects'],
        ['public/uploads/media', StorageCategory::Media, 'media_uploads'],
        ['var/geo', StorageCategory::Other, 'geodata'],
        ['var/demo', StorageCategory::Other, 'var_other'],
        ['public/uploads/demo', StorageCategory::Media, 'media_uploads'],
        ['var', StorageCategory::Temporary, 'var_other'],
        ['vendor', StorageCategory::Application, 'vendor'],
        ['node_modules', StorageCategory::Application, 'node_modules'],
        ['public/build', StorageCategory::Application, 'assets'],
        ['public/assets', StorageCategory::Application, 'assets'],
        ['.git', StorageCategory::Application, 'git'],
        ['.env.local', StorageCategory::Other, 'env_local'],
    ];

    /** @var list<array{string, StorageCategory, string}> */
    private const array DEPLOY_ROOT_RULES = [
        ['.dep', StorageCategory::Other, 'deploy_metadata'],
    ];

    public function classify(StorageLocation $location, string $relativePath): PathClassification
    {
        [$rules, $default] = match ($location) {
            StorageLocation::DeployRoot => [self::DEPLOY_ROOT_RULES, new PathClassification(StorageCategory::Other, 'unclassified')],
            StorageLocation::Shared => [self::APPLICATION_RULES, new PathClassification(StorageCategory::Other, 'shared_other')],
            StorageLocation::Database => [[], new PathClassification(StorageCategory::Database, 'database')],
            default => [self::APPLICATION_RULES, new PathClassification(StorageCategory::Application, 'source')],
        };

        foreach ($rules as [$prefix, $category, $contributor]) {
            if (self::isWithin($relativePath, $prefix)) {
                return new PathClassification($category, $contributor);
            }
        }

        return $default;
    }

    /**
     * Whether the path is the given path or lies below it.
     */
    public static function isWithin(string $relativePath, string $path): bool
    {
        return $relativePath === $path || str_starts_with($relativePath, $path.'/');
    }
}
