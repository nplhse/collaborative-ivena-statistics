<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital;

use App\Allocation\Application\Event\HospitalOwnershipGranted;
use App\Allocation\Application\Event\HospitalOwnershipRevoked;
use App\Allocation\Application\Event\UserAssociatedWithHospital;
use App\Allocation\Application\Event\UserDisassociatedFromHospital;
use App\Allocation\Application\Hospital\HospitalAssociationSnapshot;
use App\Allocation\Application\Hospital\HospitalRelationNotifier;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Entity\HospitalAccessGrant;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class HospitalRelationNotifierTest extends TestCase
{
    public function testOwnershipChangedDispatchesRevokedThenGranted(): void
    {
        $previous = $this->user(1);
        $current = $this->user(2);
        $hospital = $this->hospital(10, 'Klinik Nord');

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(HospitalOwnershipRevoked::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->addListener(HospitalOwnershipGranted::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $notifier = new HospitalRelationNotifier($dispatcher);
        $notifier->ownershipChanged($previous, $current, $hospital);

        self::assertCount(2, $events);
        self::assertInstanceOf(HospitalOwnershipRevoked::class, $events[0]);
        self::assertSame(1, $events[0]->userId);
        self::assertInstanceOf(HospitalOwnershipGranted::class, $events[1]);
        self::assertSame(2, $events[1]->userId);
        self::assertSame(10, $events[1]->hospitalId);
        self::assertSame('Klinik Nord', $events[1]->hospitalName);
    }

    public function testUnchangedOwnerDispatchesNothing(): void
    {
        $owner = $this->user(3);
        $hospital = $this->hospital(11, 'Klinik Süd');
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $notifier = new HospitalRelationNotifier($dispatcher);
        $notifier->ownershipChanged($owner, $owner, $hospital);
    }

    public function testFirstOwnerDispatchesOnlyGranted(): void
    {
        $current = $this->user(7);
        $hospital = $this->hospital(13, 'Neue Klinik');
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(HospitalOwnershipGranted::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->addListener(HospitalOwnershipRevoked::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        new HospitalRelationNotifier($dispatcher)->ownershipChanged(null, $current, $hospital);

        self::assertCount(1, $events);
        self::assertInstanceOf(HospitalOwnershipGranted::class, $events[0]);
        self::assertSame(7, $events[0]->userId);
    }

    public function testSnapshotWithoutPersistedGrantReturnsNull(): void
    {
        $grant = new HospitalAccessGrant();
        $grant->setUser($this->user(5));
        $grant->setHospital($this->hospital(12, 'Grant Klinik'));

        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $notifier = new HospitalRelationNotifier($dispatcher);
        self::assertNull($notifier->snapshot($grant));
        $notifier->associated($grant);
    }

    public function testAssociatedAndDisassociatedDispatchGrantEvents(): void
    {
        $user = $this->user(5);
        $hospital = $this->hospital(12, 'Grant Klinik');
        $grant = new HospitalAccessGrant();
        $grant->setUser($user);
        $grant->setHospital($hospital);
        $grantId = new \ReflectionProperty(HospitalAccessGrant::class, 'id');
        $grantId->setValue($grant, 41);

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(UserAssociatedWithHospital::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });
        $dispatcher->addListener(UserDisassociatedFromHospital::class, static function (object $event) use (&$events): void {
            $events[] = $event;
        });

        $notifier = new HospitalRelationNotifier($dispatcher);
        $notifier->associated($grant);
        $snapshot = $notifier->snapshot($grant);
        self::assertInstanceOf(HospitalAssociationSnapshot::class, $snapshot);
        $notifier->disassociated($snapshot, new \DateTimeImmutable('2026-03-02 09:00:00'));

        self::assertCount(2, $events);
        self::assertInstanceOf(UserAssociatedWithHospital::class, $events[0]);
        self::assertSame(5, $events[0]->userId);
        self::assertSame(41, $events[0]->grantId);
        self::assertInstanceOf(UserDisassociatedFromHospital::class, $events[1]);
        self::assertSame('2026-03-02 09:00:00', $events[1]->occurredAt->format('Y-m-d H:i:s'));
    }

    private function user(int $id): User
    {
        $user = new User();
        $property = new \ReflectionProperty(User::class, 'id');
        $property->setValue($user, $id);

        return $user;
    }

    private function hospital(int $id, string $name): Hospital
    {
        $hospital = new Hospital();
        $hospital->setName($name);
        $idProperty = new \ReflectionProperty(Hospital::class, 'id');
        $idProperty->setValue($hospital, $id);
        $publicId = new \ReflectionProperty(Hospital::class, 'publicId');
        $publicId->setValue($hospital, Uuid::fromString('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'));

        return $hospital;
    }
}
