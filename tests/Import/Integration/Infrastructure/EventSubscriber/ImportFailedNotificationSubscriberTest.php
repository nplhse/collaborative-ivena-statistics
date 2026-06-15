<?php

declare(strict_types=1);

namespace App\Tests\Import\Integration\Infrastructure\EventSubscriber;

use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Import\Application\Event\ImportFailed;
use App\Import\Domain\Enum\ImportStatus;
use App\Import\Infrastructure\EventSubscriber\ImportFailedNotificationSubscriber;
use App\Import\Infrastructure\Factory\ImportFactory;
use App\Import\Infrastructure\Repository\ImportRepository;
use App\Shared\Application\Notification\AdminNotification;
use App\Shared\Application\Notification\AdminNotificationSenderInterface;
use App\Shared\Application\Notification\AdminNotificationType;
use App\User\Domain\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class ImportFailedNotificationSubscriberTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    public function testOnImportFailedSendsNotification(): void
    {
        $user = UserFactory::createOne();
        $state = StateFactory::createOne();
        $dispatchArea = DispatchAreaFactory::createOne(['name' => 'Notify Area', 'state' => $state]);
        $hospital = HospitalFactory::createOne([
            'name' => 'Notify Hospital',
            'owner' => $user,
            'createdBy' => $user,
            'state' => $state,
            'dispatchArea' => $dispatchArea,
        ]);
        $import = ImportFactory::createOne([
            'name' => 'Broken allocation import',
            'hospital' => $hospital,
            'createdBy' => $user,
            'status' => ImportStatus::FAILED,
            'rowCount' => 42,
            'runTime' => 1500,
        ]);
        $importId = (int) $import->getId();

        $sender = $this->createMock(AdminNotificationSenderInterface::class);
        $sender->expects(self::once())
            ->method('send')
            ->with(self::callback(function (AdminNotification $notification) use ($importId): bool {
                self::assertSame(AdminNotificationType::ImportFailed, $notification->type);
                self::assertSame('Broken allocation import', $notification->templateContext['importName'] ?? null);
                self::assertSame('Notify Hospital', $notification->templateContext['hospitalName'] ?? null);
                self::assertSame(ImportStatus::FAILED->value, $notification->templateContext['status'] ?? null);
                self::assertSame(42, $notification->templateContext['rowCount'] ?? null);
                self::assertSame(1500, $notification->templateContext['runTimeMs'] ?? null);
                self::assertSame('CSV file missing', $notification->templateContext['reason'] ?? null);
                self::assertIsString($notification->templateContext['importDetailUrl'] ?? null);
                self::assertSame((string) $importId, $notification->referenceId);

                return true;
            }));

        $subscriber = new ImportFailedNotificationSubscriber(
            self::getContainer()->get(ImportRepository::class),
            $sender,
            self::getContainer()->get(UrlGeneratorInterface::class),
        );

        $subscriber->onImportFailed(new ImportFailed($importId, 'CSV file missing'));
    }
}
