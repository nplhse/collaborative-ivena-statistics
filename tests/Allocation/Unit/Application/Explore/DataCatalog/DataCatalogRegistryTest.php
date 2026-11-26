<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Explore\DataCatalog;

use App\Allocation\Application\Explore\DataCatalog\DataCatalogContributorInterface;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogRegistry;
use App\Allocation\Application\Explore\DataCatalog\DataObjectDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableGroupDefinition;
use PHPUnit\Framework\TestCase;

final class DataCatalogRegistryTest extends TestCase
{
    public function testCollectsObjectsOfAllContributorsInOrder(): void
    {
        $registry = new DataCatalogRegistry([
            $this->contributor($this->object('core', ['core.a', 'core.b'])),
            $this->contributor($this->object('plugin', ['plugin.a'], plugin: true)),
        ]);

        $definitions = $registry->definitions();

        self::assertSame(['core', 'plugin'], array_map(static fn (DataObjectDefinition $definition): string => $definition->key, $definitions));
        self::assertTrue($definitions[1]->contributedByPlugin);
    }

    public function testRejectsDuplicateObjectKeys(): void
    {
        $registry = new DataCatalogRegistry([
            $this->contributor($this->object('allocation', ['allocation.a'])),
            $this->contributor($this->object('allocation', ['allocation.b'])),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate data catalog object key "allocation".');

        $registry->definitions();
    }

    public function testRejectsDuplicateVariableKeysAcrossObjects(): void
    {
        $registry = new DataCatalogRegistry([
            $this->contributor($this->object('core', ['shared.age'])),
            $this->contributor($this->object('plugin', ['shared.age'])),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Duplicate data catalog variable key "shared.age".');

        $registry->definitions();
    }

    /**
     * @param list<string> $variableKeys
     */
    private function object(string $key, array $variableKeys, bool $plugin = false): DataObjectDefinition
    {
        return new DataObjectDefinition(
            $key,
            \stdClass::class,
            'allocation',
            'tabler:database',
            [new VariableGroupDefinition('time', array_map(static fn (string $variable): VariableDefinition => new VariableDefinition($variable, 'createdAt'), $variableKeys))],
            $plugin,
        );
    }

    private function contributor(DataObjectDefinition $object): DataCatalogContributorInterface
    {
        return new readonly class($object) implements DataCatalogContributorInterface {
            public function __construct(private DataObjectDefinition $object)
            {
            }

            #[\Override]
            public function dataObjects(): array
            {
                return [$this->object];
            }
        };
    }
}
