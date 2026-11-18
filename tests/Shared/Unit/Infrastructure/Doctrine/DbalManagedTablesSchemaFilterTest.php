<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Infrastructure\Doctrine;

use App\Shared\Application\Database\DbalManagedTablesInterface;
use App\Shared\Infrastructure\Doctrine\DbalManagedTablesSchemaFilter;
use Doctrine\DBAL\Schema\AbstractAsset;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Sequence;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\TestCase;

final class DbalManagedTablesSchemaFilterTest extends TestCase
{
    public function testHidesListedTablesAndTheirSequences(): void
    {
        $filter = new DbalManagedTablesSchemaFilter([$this->provider(['closure_event', 'generation_run'])]);

        self::assertFalse($filter('closure_event'));
        self::assertFalse($filter('public.closure_event'));
        self::assertFalse($filter('closure_event_id_seq'));
        self::assertFalse($filter('generation_run'));
        self::assertFalse($filter('generation_run_id_seq'));
    }

    public function testKeepsEveryOtherAsset(): void
    {
        $filter = new DbalManagedTablesSchemaFilter([$this->provider(['generation_run'])]);

        self::assertTrue($filter('allocation'));
        self::assertTrue($filter('allocation_id_seq'));
        self::assertTrue($filter('generation_run_hospital'), 'Only listed tables, not tables that share a prefix.');
        self::assertTrue($filter('messenger_messages'));
    }

    public function testReadsTheNameOfSchemaAssetObjects(): void
    {
        // SchemaTool passes table and sequence objects instead of names, qualified or not.
        $filter = new DbalManagedTablesSchemaFilter([$this->provider(['closure_event'])]);
        $column = Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->create();

        self::assertFalse($filter(Table::editor()->setUnquotedName('closure_event', 'public')->setColumns($column)->create()));
        self::assertFalse($filter(Sequence::editor()->setUnquotedName('closure_event_id_seq')->create()));
        self::assertTrue($filter(Table::editor()->setUnquotedName('allocation')->setColumns($column)->create()));
    }

    public function testKeepsAssetsWithoutAName(): void
    {
        $filter = new DbalManagedTablesSchemaFilter([$this->provider(['closure_event'])]);

        self::assertTrue($filter(self::createStub(AbstractAsset::class)));
    }

    public function testWithoutProvidersNothingIsHidden(): void
    {
        self::assertTrue((new DbalManagedTablesSchemaFilter())('closure_event'));
    }

    /**
     * @param list<string> $tables
     */
    private function provider(array $tables): DbalManagedTablesInterface
    {
        return new readonly class($tables) implements DbalManagedTablesInterface {
            /**
             * @param list<string> $tables
             */
            public function __construct(private array $tables)
            {
            }

            #[\Override]
            public function tables(): array
            {
                return $this->tables;
            }
        };
    }
}
