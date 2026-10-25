<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Application\Contract\ClosureVolumeDiagnosticsInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:statistics:closure-volume-diagnostics',
    description: 'Read-only data checks for the closure volume refactoring: reference quality, coverage, export boundaries, provisional events.',
)]
final readonly class ClosureVolumeDiagnosticsCommand
{
    public function __construct(private ClosureVolumeDiagnosticsInterface $diagnostics)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Limit to one hospital id')]
        ?int $hospital = null,
        #[Option(description: 'Reference weeks on each side of an event for the provisional estimate')]
        int $weeks = 4,
    ): int {
        $io->title('Closure volume diagnostics');

        $io->section('Reference quality (urgency metrics closed by the event, current projection)');
        $io->table(
            ['Hospital', 'Scope', 'Rows', 'Weekday/hour', 'Day-time bucket', 'Insufficient', 'Insufficient %', 'Events w/ insufficient closure rows'],
            array_map(static fn (array $row): array => [
                $row['hospital_id'],
                $row['scope'],
                $row['rows'],
                $row['weekday_hour'],
                $row['day_time_bucket'],
                $row['insufficient'],
                self::percent($row['insufficient'], $row['rows']),
                $row['events_with_insufficient_closure_rows'],
            ], $this->diagnostics->referenceQuality($hospital)),
        );

        $io->section('Derived coverage');
        $io->table(
            ['Hospital', 'Kind', 'Derived', 'Ranges', 'First start', 'Last end', 'Gaps'],
            array_map(static fn (array $row): array => [
                $row['hospital_id'],
                $row['kind'],
                $row['derived'] ? 'yes' : 'no – run app:import:derive-export-periods',
                $row['ranges'],
                $row['first_start'] ?? '–',
                $row['last_end'] ?? '–',
                [] === $row['gaps'] ? '–' : implode("\n", \array_slice($row['gaps'], 0, 5)).(\count($row['gaps']) > 5 ? "\n…" : ''),
            ], $this->diagnostics->coverage($hospital)),
        );

        $io->section('Closures at export boundaries');
        $io->table(
            ['Hospital', 'Intervals ending at import end', 'Continued in next import', 'Ending at month boundary', 'export_boundary relations'],
            array_map(static fn (array $row): array => [
                $row['hospital_id'],
                $row['intervals_at_import_end'],
                $row['with_continuation'],
                $row['at_month_boundary'],
                $row['export_boundary_relations'],
            ], $this->diagnostics->exportBoundaries($hospital)),
        );

        $io->section(sprintf('Provisional events with a symmetric ±%d-week window', $weeks));
        $io->table(
            ['Hospital', 'Events', 'Final', 'Awaiting allocations', 'Awaiting closures', 'No data before event'],
            array_map(static fn (array $row): array => $row['not_derived']
                ? [$row['hospital_id'], $row['events'], 'coverage not derived', '–', '–', '–']
                : [
                    $row['hospital_id'],
                    $row['events'],
                    $row['final'],
                    $row['awaiting_allocations'],
                    $row['awaiting_closures'],
                    $row['incomplete_before'],
                ], $this->diagnostics->provisional($hospital, $weeks)),
        );

        return Command::SUCCESS;
    }

    private static function percent(int $part, int $total): string
    {
        return 0 === $total ? '–' : sprintf('%.1f %%', 100 * $part / $total);
    }
}
