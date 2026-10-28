<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Generator\Run;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\DataFixtures\Generator\Application\GeneratedMonth;
use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunHospitalView;
use App\DataFixtures\Generator\Application\Run\GenerationRunReason;
use App\DataFixtures\Generator\Application\Run\GenerationRunRequest;
use App\DataFixtures\Generator\Application\Run\GenerationRunScope;
use App\DataFixtures\Generator\Application\Run\GenerationRunStage;
use App\DataFixtures\Generator\Application\Run\GenerationRunStatus;
use App\DataFixtures\Generator\Application\Run\GenerationRunTracker;
use App\DataFixtures\Generator\Application\Run\GenerationRunView;
use App\DataFixtures\Generator\Application\Run\Message\FinishGenerationRun;
use App\DataFixtures\Generator\Application\Run\Message\ProjectGeneratedHospital;
use App\DataFixtures\Generator\Application\Run\MessageHandler\FinishGenerationRunHandler;
use App\DataFixtures\Generator\Application\Run\MessageHandler\ProjectGeneratedHospitalHandler;
use App\DataFixtures\Generator\Infrastructure\Notification\GenerationRunNotificationReconciler;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunRecordedJobs;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunRetriesExhaustedSubscriber;
use App\DataFixtures\Generator\Infrastructure\Run\GenerationRunStore;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

/**
 * State changes of a generation run (#791, ADR 023): every transition is conditional, so retries and
 * concurrent deliveries cannot move a hospital backwards, and a run reaches its terminal state once.
 *
 * Hospitals here have no imports, so the asynchronous stage only rebuilds empty projections.
 */
#[ResetDatabase]
final class GenerationRunStateTest extends KernelTestCase
{
    use Factories;

    public function testTheSynchronousStageHandsOnlyHospitalsWithDataToTheAsynchronousStage(): void
    {
        [$run, $withData, $withoutData] = $this->runWithTwoHospitals();
        $store = $this->store();

        // Without a Messenger worker in this test, ending the stage would project at once; check the store step alone.
        self::assertSame([$withData], $store->endGeneration($run, GenerationRunReason::NOTHING_GENERATED, $this->now()));

        $view = $this->view($run);
        self::assertSame(GenerationRunStatus::Running, $view->status);
        self::assertSame(GenerationRunStage::Projecting, $view->stage);
        self::assertNotNull($view->generatedAt);
        self::assertSame(GenerationRunHospitalStatus::Generated, $this->hospital($view, $withData)->status);
        self::assertSame(1, $this->hospital($view, $withData)->months);
        self::assertSame(120, $this->hospital($view, $withData)->allocations);
        self::assertSame(GenerationRunHospitalStatus::Failed, $this->hospital($view, $withoutData)->status);
        self::assertSame(GenerationRunReason::NOTHING_GENERATED, $this->hospital($view, $withoutData)->reasonCode);
    }

    public function testAHospitalIsClaimedAgainOnlyWhileItsStageIsOpen(): void
    {
        [$run, $hospital] = $this->runWithTwoHospitals();
        $store = $this->store();
        $store->endGeneration($run, GenerationRunReason::NOTHING_GENERATED, $this->now());

        self::assertTrue($store->claimProjection($run, $hospital, $this->now()), 'generated → projecting');
        self::assertTrue($store->claimProjection($run, $hospital, $this->now()), 'a Messenger retry of the same attempt');
        self::assertSame(2, $this->hospital($this->view($run), $hospital)->projectionAttempts);
        self::assertTrue($store->completeHospital($run, $hospital, $this->now()));
        self::assertFalse($store->claimProjection($run, $hospital, $this->now()), 'a late delivery for a completed hospital');
        self::assertFalse($store->completeHospital($run, $hospital, $this->now()));
        self::assertFalse($store->failHospital($run, $hospital, 'late', null, $this->now()), 'completed stays completed');
        self::assertSame(GenerationRunHospitalStatus::Completed, $this->hospital($this->view($run), $hospital)->status);
    }

    public function testTheFinalStageAndTheTerminalTransitionHappenOnce(): void
    {
        [$run, $hospital] = $this->runWithTwoHospitals();
        $store = $this->store();
        $store->endGeneration($run, GenerationRunReason::NOTHING_GENERATED, $this->now());

        self::assertFalse($store->claimFinishing($run, $this->now()), 'a hospital is still open');
        $store->claimProjection($run, $hospital, $this->now());
        $store->completeHospital($run, $hospital, $this->now());

        self::assertTrue($store->claimFinishing($run, $this->now()));
        self::assertFalse($store->claimFinishing($run, $this->now()), 'only one caller starts the final stage');
        self::assertSame(GenerationRunStatus::Failed, $store->finish($run, GenerationRunReason::HOSPITAL_FAILED, $this->now()), 'the hospital without data failed');
        self::assertNull($store->finish($run, GenerationRunReason::HOSPITAL_FAILED, $this->now()), 'a second finish loses');
        self::assertFalse($store->failRun($run, 'late', $this->now()), 'a finished run is not failed afterwards');

        $view = $this->view($run);
        self::assertSame(GenerationRunReason::HOSPITAL_FAILED, $view->reasonCode);
        self::assertSame(GenerationRunStage::Done, $view->stage);

        // A second delivery of the final message changes nothing and notifies nobody.
        $this->finishHandler()(new FinishGenerationRun($run));
        self::assertSame(0, $this->notifications());
    }

    public function testADeliveryWhileAnotherProcessHoldsTheHospitalDoesNothing(): void
    {
        [$run, $hospital] = $this->runWithTwoHospitals();
        $this->store()->endGeneration($run, GenerationRunReason::NOTHING_GENERATED, $this->now());

        /** @var LockFactory $locks */
        $locks = self::getContainer()->get(LockFactory::class);
        $held = $locks->createLock(ProjectGeneratedHospitalHandler::lockKey($run, $hospital), null);
        self::assertTrue($held->acquire(false));
        try {
            $this->projectHandler()(new ProjectGeneratedHospital($run, $hospital));
        } finally {
            $held->release();
        }

        $view = $this->hospital($this->view($run), $hospital);
        self::assertSame(GenerationRunHospitalStatus::Generated, $view->status, 'not claimed, the holder projects it');
        self::assertSame(0, $view->projectionAttempts);

        $this->projectHandler()(new ProjectGeneratedHospital($run, $hospital));
        self::assertSame(GenerationRunHospitalStatus::Completed, $this->hospital($this->view($run), $hospital)->status);
        self::assertSame(1, $this->hospital($this->view($run), $hospital)->projectionAttempts);
    }

    public function testGivenUpProjectionsFailTheHospitalAndARetryCompletesTheRun(): void
    {
        [$run, $hospital, $other] = $this->runWithTwoHospitals(notify: true, secondWithData: true);
        $store = $this->store();
        $store->endGeneration($run, GenerationRunReason::NOTHING_GENERATED, $this->now());
        $store->claimProjection($run, $hospital, $this->now());
        $this->projectHandler()(new ProjectGeneratedHospital($run, $other));

        // A retry follows: nothing changes.
        $retrying = new WorkerMessageFailedEvent(new Envelope(new ProjectGeneratedHospital($run, $hospital)), 'async_priority_low', new \RuntimeException('deadlock'));
        $retrying->setForRetry();
        $this->subscriber()->onMessageFailed($retrying);
        self::assertSame(GenerationRunHospitalStatus::Projecting, $this->hospital($this->view($run), $hospital)->status);

        // Messenger gives up: the hospital fails and the run finishes without it.
        $this->subscriber()->onMessageFailed(new WorkerMessageFailedEvent(new Envelope(new ProjectGeneratedHospital($run, $hospital)), 'async_priority_low', new \RuntimeException('deadlock')));
        $view = $this->view($run);
        self::assertSame(GenerationRunHospitalStatus::Failed, $this->hospital($view, $hospital)->status);
        self::assertSame(GenerationRunReason::RETRIES_EXHAUSTED, $this->hospital($view, $hospital)->reasonCode);
        self::assertSame(\RuntimeException::class, $this->hospital($view, $hospital)->error, 'the class, not the message');
        self::assertSame(GenerationRunStatus::Failed, $view->status);
        self::assertSame(1, $this->notifications(), 'only the owner of the completed hospital');
        self::assertSame(1, $this->notifications($other));

        // The retry command reopens the failed hospital; the run completes and notifies its owner too.
        $retry = new CommandTester(new Application(self::$kernel)->find('app:generate:run:retry'));
        self::assertSame(Command::SUCCESS, $retry->execute(['run' => $run]), $retry->getDisplay());
        self::assertStringContainsString('1 hospital(s): '.$hospital, $retry->getDisplay());
        self::assertSame(GenerationRunStatus::Completed, $this->view($run)->status);
        self::assertSame(GenerationRunHospitalStatus::Completed, $this->hospital($this->view($run), $hospital)->status);
        self::assertSame(Command::SUCCESS, $retry->execute(['run' => $run]));
        self::assertStringContainsString('Nothing to retry', $retry->getDisplay());
        self::assertSame(2, $this->notifications());

        // Reconciliation publishes again; deduplication keeps one notification per run and hospital.
        /** @var GenerationRunNotificationReconciler $reconciler */
        $reconciler = self::getContainer()->get(GenerationRunNotificationReconciler::class);
        self::assertSame(2, $reconciler->reconcile($this->now()->modify('-1 hour'), $this->now()->modify('+1 hour')));
        self::assertSame(2, $this->notifications());
    }

    public function testTheBackgroundStagesAreRecordedAsJobs(): void
    {
        self::assertSame(
            [ProjectGeneratedHospital::class => 'generation_project_hospital', FinishGenerationRun::class => 'generation_finish_run'],
            new GenerationRunRecordedJobs()->recordedJobs(),
        );
    }

    public function testARunWithoutNotifyNotifiesNobody(): void
    {
        [$run] = $this->runWithTwoHospitals();
        $this->tracker()->generationFinished($run);

        self::assertSame(GenerationRunStatus::Failed, $this->view($run)->status, 'one hospital had no data');
        self::assertSame(0, $this->notifications());
    }

    public function testARunWithoutGeneratedHospitalsFailsAtOnce(): void
    {
        $owner = UserFactory::createOne();
        $hospital = HospitalFactory::createOne(['owner' => $owner]);
        $run = $this->tracker()->start(new GenerationRunRequest(GenerationRunScope::One, [(int) $hospital->getId()], seed: 7, notify: true));

        self::assertSame([], $this->tracker()->generationFinished($run));
        $view = $this->view($run);
        self::assertSame(GenerationRunStatus::Failed, $view->status);
        self::assertSame(GenerationRunReason::NOTHING_GENERATED, $view->reasonCode);
        self::assertSame(0, $this->notifications());
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function runWithTwoHospitals(bool $notify = false, bool $secondWithData = false): array
    {
        $first = HospitalFactory::createOne(['owner' => UserFactory::createOne()]);
        $second = HospitalFactory::createOne(['owner' => UserFactory::createOne()]);
        $firstId = (int) $first->getId();
        $secondId = (int) $second->getId();

        $tracker = $this->tracker();
        $run = $tracker->start(new GenerationRunRequest(GenerationRunScope::Selected, [$firstId, $secondId], seed: 7, fromMonth: '2026-08', toMonth: '2026-08', settings: ['profile' => 'test'], notify: $notify));
        $tracker->hospitalStarted($run, $firstId);
        // Import ids that do not exist: the asynchronous stage then rebuilds empty projections.
        $tracker->monthGenerated($run, $firstId, new GeneratedMonth(importId: 900001, allocations: 120, mciCases: 1, closureImportId: 900002, closures: 4));
        $tracker->hospitalStarted($run, $secondId);
        if ($secondWithData) {
            $tracker->monthGenerated($run, $secondId, new GeneratedMonth(importId: 900003, allocations: 80, mciCases: 0, closureImportId: 900004, closures: 2));
        }

        return [$run, $firstId, $secondId];
    }

    private function notifications(?int $hospitalId = null): int
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return (int) $connection->fetchOne(
            "SELECT COUNT(*) FROM notification WHERE type = 'generation.completed'".(null === $hospitalId ? '' : ' AND scope_reference_id = '.$hospitalId),
        );
    }

    private function hospital(GenerationRunView $view, int $hospitalId): GenerationRunHospitalView
    {
        foreach ($view->hospitals as $hospital) {
            if ($hospital->hospitalId === $hospitalId) {
                return $hospital;
            }
        }
        self::fail('hospital not in run');
    }

    private function view(string $run): GenerationRunView
    {
        $view = $this->store()->find($run);
        self::assertNotNull($view);

        return $view;
    }

    private function now(): \DateTimeImmutable
    {
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return $clock->now();
    }

    private function store(): GenerationRunStore
    {
        /** @var GenerationRunStore $store */
        $store = self::getContainer()->get(GenerationRunStore::class);

        return $store;
    }

    private function tracker(): GenerationRunTracker
    {
        /** @var GenerationRunTracker $tracker */
        $tracker = self::getContainer()->get(GenerationRunTracker::class);

        return $tracker;
    }

    private function projectHandler(): ProjectGeneratedHospitalHandler
    {
        /** @var ProjectGeneratedHospitalHandler $handler */
        $handler = self::getContainer()->get(ProjectGeneratedHospitalHandler::class);

        return $handler;
    }

    private function finishHandler(): FinishGenerationRunHandler
    {
        /** @var FinishGenerationRunHandler $handler */
        $handler = self::getContainer()->get(FinishGenerationRunHandler::class);

        return $handler;
    }

    private function subscriber(): GenerationRunRetriesExhaustedSubscriber
    {
        /** @var GenerationRunRetriesExhaustedSubscriber $subscriber */
        $subscriber = self::getContainer()->get(GenerationRunRetriesExhaustedSubscriber::class);

        return $subscriber;
    }
}
