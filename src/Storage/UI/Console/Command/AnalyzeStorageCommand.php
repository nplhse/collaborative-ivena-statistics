<?php

declare(strict_types=1);

namespace App\Storage\UI\Console\Command;

use App\Storage\Application\ByteFormatter;
use App\Storage\Application\Measurement\StorageAnalysis;
use App\Storage\Application\Measurement\StorageAnalyzer;
use App\Storage\Application\Measurement\StorageBreakdown;
use App\Storage\Application\Measurement\StorageTotal;
use App\Storage\Domain\Enum\MeasurementItemFlag;
use App\Storage\Domain\ValueObject\StorageMeasurementItem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Measures the storage now and prints the breakdown (ADR 017, "Scheduled measurement and console command").
 * Nothing is stored. Exits with 1 when files or database could not be measured.
 */
#[AsCommand(
    name: 'app:storage:analyze',
    description: 'Measure storage usage now and show it by category and location (dry run, nothing is stored).',
)]
final readonly class AnalyzeStorageCommand
{
    private const array FORMATS = ['table', 'json'];

    public function __construct(
        private StorageAnalyzer $analyzer,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Output format: table or json')]
        string $format = 'table',
    ): int {
        if (!\in_array($format, self::FORMATS, true)) {
            $io->error(sprintf('Unknown format "%s"; use table or json.', $format));

            return Command::INVALID;
        }

        $analysis = $this->analyzer->analyze();

        if ('json' === $format) {
            $io->writeln(json_encode($this->payload($analysis), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
        } else {
            $this->render($io, $analysis);
        }

        return $analysis->isComplete() ? Command::SUCCESS : Command::FAILURE;
    }

    private function render(SymfonyStyle $io, StorageAnalysis $analysis): void
    {
        $measurement = $analysis->measurement;
        $breakdown = new StorageBreakdown($measurement->getItems());
        $used = $measurement->getUsedBytes();

        $io->title('Storage analysis');
        $io->definitionList(
            ['Mode' => $measurement->getMode()->value],
            ['Root' => $analysis->layout->root],
            ['Measured at' => $measurement->getMeasuredAt()->format('Y-m-d H:i:s')],
            ['Duration' => sprintf('%.1f s', $measurement->getDurationMs() / 1000)],
            ['Entries scanned' => number_format($analysis->scannedEntries)],
        );

        $io->section('By category');
        $io->table(['Category', 'Size', 'Share'], array_map(
            static fn (StorageTotal $total): array => [$total->key, self::size($total), self::share($total->bytes, $used)],
            $breakdown->byCategory(),
        ));

        $io->section('By location');
        $io->table(['Location', 'Detail', 'Size', 'Hints'], array_map(
            static fn (StorageTotal $total): array => [$total->key, $total->detail ?? '', self::size($total), self::flags($total->flags)],
            $breakdown->byLocation(),
        ));

        if ($io->isVerbose()) {
            $io->section('Contributors');
            $io->table(['Location', 'Detail', 'Category', 'Contributor', 'Size', 'Status', 'Hints'], array_map(
                static fn (StorageMeasurementItem $item): array => [
                    $item->location->value,
                    $item->locationDetail ?? '',
                    $item->category->value,
                    $item->contributor,
                    null === $item->bytes ? '-' : ByteFormatter::format($item->bytes),
                    $item->status->value,
                    self::flags($item->flags),
                ],
                $measurement->getItems(),
            ));
        }

        $capacity = $measurement->getCapacityBytes();
        $database = $measurement->getDatabaseBytes();
        $filesystemTotal = $measurement->getFilesystemTotalBytes();
        $filesystemFree = $measurement->getFilesystemFreeBytes();

        $io->section('Capacity');
        $io->definitionList(
            ['Used (files + database)' => null === $used ? 'unknown' : ByteFormatter::format($used)],
            ['Database' => null === $database ? 'unknown' : ByteFormatter::format($database)],
            ['Reference' => $measurement->getCapacitySource()->value.(null === $capacity ? '' : ' ('.ByteFormatter::format($capacity).')')],
            ['Level' => $measurement->getLevel()->value],
            ['Filesystem' => null === $filesystemTotal || null === $filesystemFree
                ? 'unknown'
                : sprintf('%s free of %s', ByteFormatter::format($filesystemFree), ByteFormatter::format($filesystemTotal))],
        );

        if ([] !== $analysis->failures) {
            $io->warning(array_merge([sprintf('%d part(s) could not be measured:', \count($analysis->failures))], \array_slice($analysis->failures, 0, 20)));
        }

        $io->note('Dry run: nothing was stored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(StorageAnalysis $analysis): array
    {
        $measurement = $analysis->measurement;
        $breakdown = new StorageBreakdown($measurement->getItems());
        $totals = static fn (StorageTotal $total): array => [
            'key' => $total->key,
            'detail' => $total->detail,
            'bytes' => $total->bytes,
            'complete' => $total->complete,
            'flags' => array_map(static fn (MeasurementItemFlag $flag): string => $flag->value, $total->flags),
        ];

        return [
            'mode' => $measurement->getMode()->value,
            'root' => $analysis->layout->root,
            'measured_at' => $measurement->getMeasuredAt()->format(\DATE_ATOM),
            'duration_ms' => $measurement->getDurationMs(),
            'scanned_entries' => $analysis->scannedEntries,
            'used_bytes' => $measurement->getUsedBytes(),
            'database_bytes' => $measurement->getDatabaseBytes(),
            'capacity_source' => $measurement->getCapacitySource()->value,
            'capacity_bytes' => $measurement->getCapacityBytes(),
            'budget_bytes' => $measurement->getBudgetBytes(),
            'filesystem_total_bytes' => $measurement->getFilesystemTotalBytes(),
            'filesystem_free_bytes' => $measurement->getFilesystemFreeBytes(),
            'level' => $measurement->getLevel()->value,
            'categories' => array_map($totals, $breakdown->byCategory()),
            'locations' => array_map($totals, $breakdown->byLocation()),
            'items' => array_map(static fn (StorageMeasurementItem $item): array => $item->toArray(), $measurement->getItems()),
            'failures' => $analysis->failures,
        ];
    }

    private static function size(StorageTotal $total): string
    {
        return ByteFormatter::format($total->bytes).($total->complete ? '' : ' (incomplete)');
    }

    private static function share(int $bytes, ?int $used): string
    {
        return null === $used || 0 === $used ? '-' : sprintf('%.1f %%', (float) $bytes / (float) $used * 100.0);
    }

    /**
     * @param list<MeasurementItemFlag> $flags
     */
    private static function flags(array $flags): string
    {
        return implode(', ', array_map(static fn (MeasurementItemFlag $flag): string => $flag->value, $flags));
    }
}
