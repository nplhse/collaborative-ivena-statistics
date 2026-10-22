<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Notification;

use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Import\Application\Service\ImportMilestoneRecorder;
use App\Import\Application\Service\ImportRunLifecycle;
use App\Import\Domain\Entity\Import;
use App\Import\Domain\Entity\UserImportMilestone;
use App\Import\Domain\Enum\ImportMilestoneType;
use App\Import\Domain\Enum\ImportRunTrigger;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Domain\Enum\ImportType;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Notification\ImportMilestoneNotifier;
use App\Import\Infrastructure\Notification\ImportRunNotifier;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use App\User\Domain\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class ImportNotifierEdgeCasesTest extends DatabaseKernelTestCase
{
    public function testMilestoneEntityRoundTripsThroughItsConstructor(): void
    {
        $user = UserFactory::createOne();
        $entityManager = $this->entityManager();
        $milestone = new UserImportMilestone($user, ImportMilestoneType::FirstImport, new \DateTimeImmutable('2026-10-22 10:00:00'));
        $entityManager->persist($milestone);
        $entityManager->flush();
        $entityManager->clear();

        $loaded = $entityManager->find(UserImportMilestone::class, $milestone->getId());

        self::assertInstanceOf(UserImportMilestone::class, $loaded);
        self::assertSame($this->connection()->fetchOne('SELECT milestone_type FROM user_import_milestone WHERE id = ?', [$milestone->getId()]), $loaded->getMilestoneType()->value);
        self::assertSame('2026-10-22 10:00:00', $loaded->getAchievedAt()->format('Y-m-d H:i:s'));
        self::assertNull($loaded->getImportRun());
    }

    public function testRecorderSkipsImportsWithoutCreator(): void
    {
        $import = new Import();

        self::assertNull(new ImportMilestoneRecorder()->recordFirstImport($this->connection(), $import, Uuid::v7(), new \DateTimeImmutable()));
    }

    public function testMilestoneWithoutRunLinksToTheImportList(): void
    {
        $user = UserFactory::createOne();
        $milestone = new UserImportMilestone($user, ImportMilestoneType::FirstImport, new \DateTimeImmutable());
        $this->entityManager()->persist($milestone);
        $this->entityManager()->flush();

        self::getContainer()->get(ImportMilestoneNotifier::class)->notify($milestone);

        self::assertSame(
            ['type' => 'user.first_import', 'action_route' => 'app_import_index'],
            $this->connection()->fetchAssociative('SELECT type, action_route FROM notification WHERE recipient_id = ?', [$user->getId()]),
        );
    }

    public function testUnsavedMilestoneIsIgnored(): void
    {
        self::getContainer()->get(ImportMilestoneNotifier::class)->notify(
            new UserImportMilestone(UserFactory::createOne(), ImportMilestoneType::FirstImport, new \DateTimeImmutable()),
        );

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification'));
    }

    public function testOpenAndSupersededRunsAndRunsWithoutHospitalAreNotAnnounced(): void
    {
        $creator = UserFactory::createOne();
        $lifecycle = self::getContainer()->get(ImportRunLifecycle::class);
        $notifier = self::getContainer()->get(ImportRunNotifier::class);

        $importId = (int) ImportFactory::createOne([
            'hospital' => HospitalFactory::createOne(['owner' => UserFactory::new()]),
            'createdBy' => $creator,
            'type' => ImportType::ALLOCATION,
            'status' => ImportStatus::PENDING,
            'runCount' => 0,
        ])->getId();
        $open = $lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $notifier->notify($open);

        $lifecycle->register($importId, ImportRunTrigger::Upload, $creator->getId());
        $this->entityManager()->refresh($open);
        $notifier->notify($open);

        $withoutHospital = $this->entityManager()->find(Import::class, $importId);
        self::assertInstanceOf(Import::class, $withoutHospital);
        $this->connection()->executeStatement("UPDATE import_run SET status = 'completed' WHERE id = ?", [$open->getId()->toRfc4122()]);
        $this->entityManager()->refresh($open);
        $withoutHospital->setHospital(null);
        $notifier->notify($open);

        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM notification'));
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
