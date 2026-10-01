<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog;

final class ReferenceNameCatalogGuard
{
    /**
     * @param list<ReferenceNameEntry> $entries
     */
    public function assertNoConflicts(array $entries): void
    {
        $canonicalByKey = [];
        foreach ($entries as $entry) {
            $key = ReferenceNameKey::normalize($entry->name);
            if (isset($canonicalByKey[$key])) {
                throw new ReferenceCatalogAliasConflictException(sprintf('Catalog name "%s" is listed twice.', $entry->name));
            }

            $canonicalByKey[$key] = $entry->name;
        }

        $aliasByKey = [];
        foreach ($entries as $entry) {
            $canonicalKey = ReferenceNameKey::normalize($entry->name);
            $replacedKeys = [];
            foreach ($entry->previousNames as $previousName) {
                $previousKey = ReferenceNameKey::normalize($previousName);
                if ($previousKey === $canonicalKey) {
                    continue;
                }

                if (isset($canonicalByKey[$previousKey])) {
                    throw new ReferenceCatalogRenameConflictException(sprintf('Cannot rename "%s" to "%s" because both names are canonical catalog entries.', $previousName, $entry->name));
                }

                $replacedKeys[$previousKey] = $previousName;
            }

            foreach ($entry->aliases as $alias) {
                $aliasKey = ReferenceNameKey::normalize($alias->name);
                if ($aliasKey === $canonicalKey) {
                    throw new ReferenceCatalogAliasConflictException(sprintf('Alias "%s" repeats its canonical name.', $alias->name));
                }

                if (isset($aliasByKey[$aliasKey])) {
                    throw new ReferenceCatalogAliasConflictException(sprintf('Alias "%s" is already mapped to "%s".', $alias->name, $aliasByKey[$aliasKey]));
                }

                if (isset($canonicalByKey[$aliasKey]) && !isset($replacedKeys[$aliasKey])) {
                    throw new ReferenceCatalogAliasConflictException(sprintf('Alias "%s" collides with canonical name "%s".', $alias->name, $canonicalByKey[$aliasKey]));
                }

                $this->assertVariantBoundary($alias->name, $entry->name);
                $aliasByKey[$aliasKey] = $entry->name;
            }
        }
    }

    private function assertVariantBoundary(string $alias, string $canonical): void
    {
        $aliasKey = ReferenceNameKey::normalize($alias);
        $canonicalKey = ReferenceNameKey::normalize($canonical);

        if ($this->isIsolation($aliasKey) && !$this->isIsolation($canonicalKey)) {
            throw new ReferenceCatalogAliasConflictException(sprintf('Isolation label "%s" cannot alias base department "%s".', $alias, $canonical));
        }

        $aliasVentilation = $this->ventilationSide($aliasKey);
        $canonicalVentilation = $this->ventilationSide($canonicalKey);
        if (null !== $aliasVentilation && $aliasVentilation !== $canonicalVentilation) {
            throw new ReferenceCatalogAliasConflictException(sprintf('Ventilation label "%s" cannot alias "%s".', $alias, $canonical));
        }
    }

    private function isIsolation(string $key): bool
    {
        return str_contains($key, 'isolier');
    }

    private function ventilationSide(string $key): ?string
    {
        if (str_contains($key, 'mit beatmung')) {
            return 'mit';
        }

        if (str_contains($key, 'ohne beatmung')) {
            return 'ohne';
        }

        return null;
    }
}
