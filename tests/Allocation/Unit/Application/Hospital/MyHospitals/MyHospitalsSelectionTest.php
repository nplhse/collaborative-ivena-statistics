<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Application\Hospital\MyHospitals;

use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsEntry;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsScope;
use App\Allocation\Application\Hospital\MyHospitals\MyHospitalsSelection;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalPermission;
use App\Allocation\Domain\HospitalPermissionMask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class MyHospitalsSelectionTest extends TestCase
{
    private const string ALPHA = '0b0e8f3e-1d0a-4b7e-9a51-6f1f6a000001';
    private const string BETA = '0b0e8f3e-1d0a-4b7e-9a51-6f1f6a000002';

    private MyHospitalsScope $scope;

    #[\Override]
    protected function setUp(): void
    {
        $this->scope = new MyHospitalsScope([
            $this->entry(1, 'Alpha', self::ALPHA),
            $this->entry(2, 'Beta', self::BETA),
        ]);
    }

    public function testWithoutRequestedIdEveryHospitalIsShown(): void
    {
        $selection = MyHospitalsSelection::fromPublicId($this->scope, null);

        self::assertTrue($selection->isAll());
        self::assertTrue($selection->canChoose());
        self::assertSame($this->scope, $selection->selected());
        self::assertNull($selection->single);
    }

    public function testRequestedIdSelectsThatHospitalOnly(): void
    {
        $selection = MyHospitalsSelection::fromPublicId($this->scope, strtoupper(self::BETA));

        self::assertFalse($selection->isAll());
        self::assertSame($this->scope->entries[1], $selection->single);
        self::assertSame([$this->scope->entries[1]], $selection->selected()->entries);
        self::assertTrue($selection->isSelected($this->scope->entries[1]));
        self::assertFalse($selection->isSelected($this->scope->entries[0]));
    }

    public function testForeignOrMalformedIdShowsAllHospitals(): void
    {
        self::assertTrue(MyHospitalsSelection::fromPublicId($this->scope, '0b0e8f3e-1d0a-4b7e-9a51-6f1f6a000099')->isAll());
        self::assertTrue(MyHospitalsSelection::fromPublicId($this->scope, 'not-a-uuid')->isAll());
        self::assertTrue(MyHospitalsSelection::fromPublicId($this->scope, '')->isAll());
    }

    public function testOneHospitalOffersNoChoice(): void
    {
        $selection = MyHospitalsSelection::fromPublicId(new MyHospitalsScope([$this->scope->entries[0]]), null);

        self::assertFalse($selection->canChoose());
    }

    private function entry(int $id, string $name, string $publicId): MyHospitalsEntry
    {
        $hospital = new Hospital();
        $hospital->setName($name);
        $hospital->setPublicId(Uuid::fromString($publicId));
        new \ReflectionProperty(Hospital::class, 'id')->setValue($hospital, $id);

        return new MyHospitalsEntry($hospital, false, HospitalPermissionMask::fromPermissions([HospitalPermission::Statistics]));
    }
}
