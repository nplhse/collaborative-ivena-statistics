<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Service;

use App\Import\Application\DTO\ImportDataRemoval;
use App\Import\Application\Service\ImportRelatedDataCleanupService;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Enum\ImportType;
use App\Statistics\Application\Contract\ClosureRebuildRequestInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A reprocess removes the previous rows before it runs. If it then fails without rows of its own, the closure
 * figures still have to be rebuilt.
 */
final class ImportFailedRunRebuildRequestTest extends KernelTestCase
{
    /**
     * @param array{analysis: int, volume: int} $expected
     */
    #[DataProvider('cases')]
    public function testRebuildRequests(ImportType $type, bool $previousRunRemoved, int $allocations, array $expected): void
    {
        self::bootKernel();
        $requests = $this->createMock(ClosureRebuildRequestInterface::class);
        $requests->expects($this->exactly($expected['analysis']))->method('requestAnalysis');
        $requests->expects($this->exactly($expected['volume']))->method('requestVolume');
        self::getContainer()->set(ClosureRebuildRequestInterface::class, $requests);

        $removal = new ImportDataRemoval(
            ['projection' => 0, 'rejects' => 0, 'allocations' => $allocations, 'closure_intervals' => 0, 'assessments' => 0, 'mci_cases' => 0],
            false,
        );

        self::getContainer()->get(ImportRelatedDataCleanupService::class)
            ->completeFailedRunRemoval(new Import()->setType($type), $removal, $previousRunRemoved);
    }

    /**
     * @return iterable<string, array{ImportType, bool, int, array{analysis: int, volume: int}}>
     */
    public static function cases(): iterable
    {
        yield 'first run without rows' => [ImportType::ALLOCATION, false, 0, ['analysis' => 0, 'volume' => 0]];
        yield 'allocation reprocess without rows' => [ImportType::ALLOCATION, true, 0, ['analysis' => 0, 'volume' => 1]];
        yield 'closure reprocess without rows' => [ImportType::CLOSURE, true, 0, ['analysis' => 1, 'volume' => 0]];
        yield 'reprocess with rows requests once' => [ImportType::ALLOCATION, true, 2, ['analysis' => 0, 'volume' => 1]];
    }
}
