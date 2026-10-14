<?php

declare(strict_types=1);

namespace App\Statistics\UI\Console\Command;

use App\Statistics\Application\Contract\ClosureVolumeSnapshotInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:statistics:closure-volume-snapshot',
    description: 'Save, compare, list or drop named snapshots of closure_volume_hour.',
)]
final readonly class ClosureVolumeSnapshotCommand
{
    public function __construct(private ClosureVolumeSnapshotInterface $snapshots)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'save, compare, list or drop')]
        string $action,
        #[Argument(description: 'Snapshot name (a–z, 0–9, _)')]
        ?string $name = null,
        #[Option(description: 'Limit to one hospital id')]
        ?int $hospital = null,
        #[Option(description: 'Absolute tolerance for numeric columns')]
        string $tolerance = '0.0001',
    ): int {
        if ('list' === $action) {
            $rows = array_map(static fn (array $snapshot): array => [$snapshot['name'], $snapshot['rows']], $this->snapshots->list());
            [] === $rows ? $io->note('No snapshots.') : $io->table(['Name', 'Rows'], $rows);

            return Command::SUCCESS;
        }
        if (null === $name || '' === $name) {
            $io->error('A snapshot name is required.');

            return Command::INVALID;
        }

        try {
            return match ($action) {
                'save' => $this->save($io, $name, $hospital),
                'compare' => $this->compare($io, $name, $hospital, (float) $tolerance),
                'drop' => $this->drop($io, $name),
                default => $this->unknown($io, $action),
            };
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return Command::INVALID;
        }
    }

    private function save(SymfonyStyle $io, string $name, ?int $hospital): int
    {
        $rows = $this->snapshots->save($name, $hospital);
        $io->success(sprintf('Snapshot "%s" stored with %d rows.', $name, $rows));

        return Command::SUCCESS;
    }

    private function compare(SymfonyStyle $io, string $name, ?int $hospital, float $tolerance): int
    {
        $result = $this->snapshots->compare($name, $hospital, $tolerance);
        $io->definitionList(
            ['Snapshot rows' => $result['snapshot_rows']],
            ['Current rows' => $result['current_rows']],
            ['Only in snapshot' => $result['only_in_snapshot']],
            ['Only in current' => $result['only_in_current']],
            ['Changed rows' => $result['changed_rows']],
        );
        $changed = array_filter($result['changed_columns']);
        if ([] !== $changed) {
            $io->table(['Column', 'Changed rows'], array_map(null, array_keys($changed), array_values($changed)));
        }
        if ([] !== $result['largest_differences']) {
            $io->section('Largest differences (expected_area)');
            $io->table(array_keys($result['largest_differences'][0]), array_map(
                static fn (array $row): array => array_map(static fn (mixed $value): string => null === $value ? '∅' : (string) $value, array_values($row)),
                $result['largest_differences'],
            ));
        }
        $identical = 0 === $result['only_in_snapshot'] + $result['only_in_current'] + $result['changed_rows'];
        $identical ? $io->success('Identical within tolerance.') : $io->warning('Differences found.');

        return $identical ? Command::SUCCESS : Command::FAILURE;
    }

    private function drop(SymfonyStyle $io, string $name): int
    {
        $this->snapshots->drop($name);
        $io->success(sprintf('Snapshot "%s" dropped.', $name));

        return Command::SUCCESS;
    }

    private function unknown(SymfonyStyle $io, string $action): int
    {
        $io->error(sprintf('Unknown action "%s". Use save, compare, list or drop.', $action));

        return Command::INVALID;
    }
}
