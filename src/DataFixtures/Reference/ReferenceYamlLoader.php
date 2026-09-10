<?php

declare(strict_types=1);

namespace App\DataFixtures\Reference;

use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogDocument;
use App\Allocation\Application\ReferenceCatalog\ReferenceCatalogReader;

final readonly class ReferenceYamlLoader
{
    public function __construct(
        private ReferenceCatalogReader $reader,
    ) {
    }

    /**
     * @return list<array{state: string, name: string}>
     */
    public function areas(): array
    {
        $rows = [];
        foreach ($this->document()->dispatchAreas as $row) {
            if (null === $row['state'] || '' === $row['state']) {
                continue;
            }

            $rows[] = ['state' => $row['state'], 'name' => $row['name']];
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function names(string $filename): array
    {
        return $this->document()->names($filename);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function hospitals(): array
    {
        return $this->document()->hospitals;
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function indicationsNormalized(): array
    {
        return $this->document()->indicationsNormalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function indicationsRaw(): array
    {
        return $this->document()->indicationsRaw;
    }

    /**
     * @return list<array{name: string, category: ?string, codes: list<string>}>
     */
    public function indicationGroups(): array
    {
        return $this->document()->indicationGroups;
    }

    private function document(): ReferenceCatalogDocument
    {
        return $this->reader->loadDefault();
    }
}
