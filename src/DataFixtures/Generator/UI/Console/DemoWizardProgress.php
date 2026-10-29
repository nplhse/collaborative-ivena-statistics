<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\UI\Console;

use App\DataFixtures\Generator\Application\Dataset\DatasetHospitalPlan;
use App\DataFixtures\Generator\Application\Dataset\DatasetProgress;
use App\DataFixtures\Generator\Application\GeneratedMonth;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Live view of the demo wizard (#791): one line per stage (participant, hospital, location, monthly imports,
 * allocations, closures, background projections) with its state and real counters. On a terminal each stage is a
 * console section that is rewritten in place; elsewhere (pipes, tests) a line is written per change.
 */
final class DemoWizardProgress implements DatasetProgress
{
    public const string PARTICIPANT = 'participant';
    public const string HOSPITAL = 'hospital';
    public const string LOCATION = 'location';
    public const string IMPORTS = 'imports';
    public const string ALLOCATIONS = 'allocations';
    public const string CLOSURES = 'closures';
    public const string PROJECTIONS = 'projections';

    private const array LABELS = [
        self::PARTICIPANT => 'Participant',
        self::HOSPITAL => 'Hospital',
        self::LOCATION => 'Location',
        self::IMPORTS => 'Monthly imports',
        self::ALLOCATIONS => 'Allocations',
        self::CLOSURES => 'Closures',
        self::PROJECTIONS => 'Projections',
    ];

    private const int BAR_WIDTH = 24;

    /** @var array<string, ConsoleSectionOutput> */
    private array $sections = [];

    /** @var array<string, array{0: StageState, 1: string}> */
    private array $stages = [];

    private int $months = 0;
    private int $monthsDone = 0;
    private int $plannedAllocations = 0;
    private int $allocations = 0;
    private int $closures = 0;
    private int $mciCases = 0;

    public function __construct(private readonly OutputInterface $output)
    {
        foreach (array_keys(self::LABELS) as $stage) {
            $this->stages[$stage] = [StageState::Pending, ''];
        }
        if ($output instanceof ConsoleOutputInterface && $output->isDecorated()) {
            foreach (array_keys(self::LABELS) as $stage) {
                $this->sections[$stage] = $output->section();
                $this->render($stage);
            }
        }
    }

    public function plan(int $months, int $plannedAllocations): void
    {
        $this->months = $months;
        $this->plannedAllocations = $plannedAllocations;
        $this->set(self::IMPORTS, StageState::Pending, $this->bar(0, $months).\sprintf(' 0/%d months', $months));
        $this->set(self::ALLOCATIONS, StageState::Pending, $this->bar(0, max(1, $plannedAllocations)).\sprintf(' 0 of about %s', number_format($plannedAllocations)));
    }

    public function set(string $stage, StageState $state, string $detail = ''): void
    {
        $previous = $this->stages[$stage] ?? null;
        $this->stages[$stage] = [$state, $detail];
        if ([] !== $this->sections) {
            $this->render($stage);

            return;
        }
        // Without sections only state changes and finished months are worth a line.
        if (null === $previous || $previous[0] !== $state || self::IMPORTS === $stage) {
            $this->output->writeln($this->line($stage));
        }
    }

    public function state(string $stage): StageState
    {
        return $this->stages[$stage][0];
    }

    #[\Override]
    public function hospitalStarted(DatasetHospitalPlan $hospital): void
    {
        $this->set(self::IMPORTS, StageState::Running, $this->bar(0, $this->months).\sprintf(' 0/%d months', $this->months));
        $this->set(self::ALLOCATIONS, StageState::Running, $this->allocationDetail());
        $this->set(self::CLOSURES, StageState::Running, $this->closureDetail());
    }

    #[\Override]
    public function monthGenerated(DatasetHospitalPlan $hospital, string $month, GeneratedMonth $generated): void
    {
        ++$this->monthsDone;
        $this->allocations += $generated->allocations;
        $this->closures += $generated->closures;
        $this->mciCases += $generated->mciCases;
        $this->set(self::IMPORTS, StageState::Running, $this->bar($this->monthsDone, $this->months).\sprintf(' %d/%d months, %s written', $this->monthsDone, $this->months, $month));
        $this->set(self::ALLOCATIONS, StageState::Running, $this->allocationDetail());
        $this->set(self::CLOSURES, StageState::Running, $this->closureDetail());
    }

    #[\Override]
    public function hospitalFinished(DatasetHospitalPlan $hospital): void
    {
        $this->set(self::IMPORTS, StageState::Completed, $this->bar($this->monthsDone, $this->months).\sprintf(' %d/%d months (%d allocation and %d closure imports)', $this->monthsDone, $this->months, $this->monthsDone, $this->monthsDone));
        $this->set(self::ALLOCATIONS, StageState::Completed, $this->allocationDetail());
        $this->set(self::CLOSURES, StageState::Completed, $this->closureDetail());
    }

    #[\Override]
    public function hospitalFailed(DatasetHospitalPlan $hospital, string $message): void
    {
        $this->set(self::IMPORTS, StageState::Failed, \sprintf('rolled back: %s', GenerationRunFormatter::error($message, $this->output->isVerbose())));
        $this->set(self::ALLOCATIONS, StageState::Failed, 'rolled back');
        $this->set(self::CLOSURES, StageState::Failed, 'rolled back');
        $this->set(self::PROJECTIONS, StageState::Failed, 'not started');
    }

    private function allocationDetail(): string
    {
        $detail = $this->bar($this->allocations, max(1, $this->plannedAllocations)).\sprintf(' %s of about %s', number_format($this->allocations), number_format($this->plannedAllocations));

        return $this->mciCases > 0 ? $detail.\sprintf(', %d MCI case(s)', $this->mciCases) : $detail;
    }

    private function closureDetail(): string
    {
        return \sprintf('%s closure interval(s)', number_format($this->closures));
    }

    private function bar(int $done, int $total): string
    {
        $filled = $total > 0 ? (int) round(min(1.0, (float) $done / (float) $total) * (float) self::BAR_WIDTH) : 0;

        return '['.str_repeat('█', $filled).str_repeat('░', self::BAR_WIDTH - $filled).']';
    }

    private function render(string $stage): void
    {
        $this->sections[$stage]->overwrite($this->line($stage));
    }

    private function line(string $stage): string
    {
        [$state, $detail] = $this->stages[$stage];

        return rtrim(\sprintf(' %s %-16s %-10s %s', $state->marker(), self::LABELS[$stage], $state->label(), $detail));
    }
}
