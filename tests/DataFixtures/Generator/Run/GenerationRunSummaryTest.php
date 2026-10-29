<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Run;

use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalView;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\Run\GenerationRunStage;
use App\DataFixtures\Generator\Application\Run\GenerationRunStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunTrigger;
use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use App\DataFixtures\Generator\Application\Run\WorkerState;
use App\DataFixtures\Generator\UI\Console\GenerationRunSummary;
use PHPUnit\Framework\TestCase;

/**
 * The wizard's overview of a run: a run started at 10:00:00, seen at 10:03:12.
 */
final class GenerationRunSummaryTest extends TestCase
{
    private const string NOW = '2026-10-29 10:03:12';

    public function testARunInTheBackgroundShowsProgressStepsAndTheWorker(): void
    {
        $run = $this->runView(GenerationRunStatus::Running, GenerationRunStage::Projecting, [
            $this->hospital('Klinikum A', GenerationRunHospitalStatus::Completed),
            $this->hospital('Klinikum B', GenerationRunHospitalStatus::Projecting, 'allocation projection', 6, 13),
            $this->hospital('Klinikum C', GenerationRunHospitalStatus::Generated),
            $this->hospital('Klinikum D', GenerationRunHospitalStatus::Failed, error: "Deadlock detected\nDETAIL: process 1 waits"),
        ]);

        $text = $this->text($run, new WorkerState(known: true, waiting: 2, inProgress: 1, oldestWaitingSeconds: 30));

        self::assertStringContainsString('Run                   statistics in the background, running for 3:12 min', $text);
        // 2 of 4 hospitals are through (one completed, one failed): half of the bar of 20.
        self::assertStringContainsString('Hospitals             ██████████░░░░░░░░░░ 1/4 done, 2 in progress, 1 failed', $text);
        self::assertStringContainsString('Klinikum B            █████████░░░░░░░░░░░ allocation projection 7/13', $text, 'working on step 7 of 13 after 6 done');
        self::assertStringContainsString('Klinikum C            waiting for the worker', $text);
        self::assertStringContainsString('Worker                busy, 1 job(s) in progress, 2 waiting', $text);
        self::assertStringContainsString('Problem               Klinikum D: Deadlock detected … (full text with -v)', $text);
        self::assertStringNotContainsString('Klinikum A ', $text, 'completed hospitals are only counted');
    }

    public function testMessagesWaitingWithoutAWorkerPointToTheWorkerCommand(): void
    {
        $run = $this->runView(GenerationRunStatus::Running, GenerationRunStage::Projecting, [$this->hospital('Klinikum B', GenerationRunHospitalStatus::Generated)]);

        $text = $this->text($run, new WorkerState(known: true, waiting: 3, inProgress: 0, oldestWaitingSeconds: 125));

        self::assertStringContainsString('no worker? 3 message(s) wait for 2:05 min: start make demo-consume', $text);
    }

    public function testTheFinalStageAndAFinishedRun(): void
    {
        $finishing = $this->runView(GenerationRunStatus::Running, GenerationRunStage::Finishing, [$this->hospital('Klinikum A', GenerationRunHospitalStatus::Completed)], finishStep: 'daily KPIs');
        self::assertStringContainsString('Final stage           daily KPIs', $this->text($finishing, new WorkerState(known: false)));
        self::assertStringContainsString('Worker                unknown', $this->text($finishing, new WorkerState(known: false)));

        $done = $this->runView(GenerationRunStatus::Completed, GenerationRunStage::Done, [$this->hospital('Klinikum A', GenerationRunHospitalStatus::Completed)], finishedAt: '2026-10-29 10:02:30');
        $text = $this->text($done, WorkerState::unknown());
        self::assertStringContainsString('Run                   completed, took 2:30 min', $text);
        self::assertStringContainsString('████████████████████ 1/1 done', $text);
        self::assertStringNotContainsString('Worker', $text, 'a finished run needs no worker');
    }

    private function text(GenerationRunView $run, WorkerState $worker): string
    {
        return strip_tags(implode("\n", GenerationRunSummary::lines($run, $worker, new \DateTimeImmutable(self::NOW))));
    }

    /**
     * @param list<GenerationRunHospitalView> $hospitals
     */
    private function runView(GenerationRunStatus $status, GenerationRunStage $stage, array $hospitals, ?string $finishStep = null, ?string $finishedAt = null): GenerationRunView
    {
        return new GenerationRunView(
            id: 'run-1',
            scope: GenerationRunScope::One,
            trigger: GenerationRunTrigger::Wizard,
            seed: 1,
            fromMonth: '2026-03',
            toMonth: '2026-08',
            settings: [],
            notify: true,
            initiatedById: null,
            status: $status,
            stage: $stage,
            reasonCode: null,
            createdAt: new \DateTimeImmutable('2026-10-29 10:00:00'),
            updatedAt: new \DateTimeImmutable('2026-10-29 10:03:00'),
            generatedAt: new \DateTimeImmutable('2026-10-29 10:01:00'),
            finishedAt: null === $finishedAt ? null : new \DateTimeImmutable($finishedAt),
            finishStep: $finishStep,
            hospitals: $hospitals,
        );
    }

    private function hospital(string $name, GenerationRunHospitalStatus $status, ?string $step = null, int $done = 0, int $total = 0, ?string $error = null): GenerationRunHospitalView
    {
        return new GenerationRunHospitalView(
            hospitalId: \strlen($name),
            hospitalName: $name,
            ownerId: null,
            status: $status,
            months: 12,
            allocations: 1000,
            closures: 10,
            mciCases: 0,
            allocationImportIds: [],
            closureImportIds: [],
            projectionAttempts: 1,
            reasonCode: null,
            error: $error,
            startedAt: null,
            generatedAt: null,
            finishedAt: null,
            projectionStep: $step,
            projectionDone: $done,
            projectionTotal: $total,
        );
    }
}
