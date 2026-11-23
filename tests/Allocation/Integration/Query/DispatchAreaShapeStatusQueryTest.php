<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Query;

use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Infrastructure\Factory\AllocationFactory;
use App\Allocation\Infrastructure\Factory\AssignmentFactory;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\Infrastructure\Factory\HospitalFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Allocation\Infrastructure\Factory\SpecialityFactory;
use App\Allocation\Infrastructure\Factory\StateFactory;
use App\Allocation\Infrastructure\Query\DispatchAreaShapeStatusQuery;
use App\Allocation\UI\Console\Command\CheckDispatchAreasCommand;
use App\Import\Infrastructure\Factory\ImportFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class DispatchAreaShapeStatusQueryTest extends KernelTestCase
{
    use Factories;

    public function testAnAreaHasAShapeOnlyWhenEveryDistrictHasAGeometry(): void
    {
        self::bootKernel();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $complete = DispatchAreaFactory::createOne(['name' => 'Complete', 'state' => $state]);
        $incomplete = DispatchAreaFactory::createOne(['name' => 'Incomplete', 'state' => $state]);
        $empty = DispatchAreaFactory::createOne(['name' => 'Empty', 'state' => $state]);

        $this->district('06001', 'One', $complete, true);
        $this->district('06002', 'Two', $complete, true);
        $this->district('06003', 'Three', $incomplete, true);
        $this->district('09004', 'Four', $incomplete, false);
        $this->em()->flush();

        $query = self::getContainer()->get(DispatchAreaShapeStatusQuery::class);
        $byName = [];
        foreach ($query->all() as $status) {
            $byName[$status->name] = $status;
        }

        self::assertTrue($byName['Complete']->hasShape());
        self::assertSame(2, $byName['Complete']->districtCount);
        self::assertFalse($byName['Incomplete']->hasShape());
        self::assertSame(['09004 Four'], $byName['Incomplete']->missingDistricts);
        self::assertSame(['09004 Four'], $byName['Incomplete']->otherStateDistricts);
        self::assertFalse($byName['Empty']->hasShape());
        self::assertSame('Empty', $query->forDispatchArea((int) $empty->getId())?->name);
    }

    public function testCheckFailsOnlyForAreasWithAllocations(): void
    {
        self::bootKernel();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        DispatchAreaFactory::createOne(['name' => 'Unused', 'state' => $state]);
        $command = new CheckDispatchAreasCommand(self::getContainer()->get(DispatchAreaShapeStatusQuery::class));

        self::assertSame(Command::SUCCESS, $this->execute($command));

        $used = DispatchAreaFactory::createOne(['name' => 'Used', 'state' => $state]);
        $hospital = HospitalFactory::createOne(['dispatchArea' => $used, 'state' => $state]);
        AllocationFactory::createOne([
            'dispatchArea' => $used,
            'state' => $state,
            'hospital' => $hospital,
            'import' => ImportFactory::createOne(['hospital' => $hospital]),
            'speciality' => SpecialityFactory::createOne(),
            'department' => DepartmentFactory::createOne(),
            'assignment' => AssignmentFactory::createOne(),
            'indicationRaw' => IndicationRawFactory::createOne(),
        ]);
        $output = new BufferedOutput();

        self::assertSame(Command::FAILURE, $command(new SymfonyStyle(new ArrayInput([]), $output)));
        self::assertStringContainsString('Used', $output->fetch());
    }

    public function testCheckNamesDistrictsOfAnotherState(): void
    {
        self::bootKernel();
        $state = StateFactory::createOne(['name' => 'Hessen']);
        $area = DispatchAreaFactory::createOne(['name' => 'Border', 'state' => $state]);
        $this->district('09001', 'Across', $area, true);
        $this->em()->flush();
        $command = new CheckDispatchAreasCommand(self::getContainer()->get(DispatchAreaShapeStatusQuery::class));
        $output = new BufferedOutput();

        self::assertSame(Command::SUCCESS, $command(new SymfonyStyle(new ArrayInput([]), $output)));
        self::assertStringContainsString('09001 Across', $output->fetch());
    }

    private function execute(CheckDispatchAreasCommand $command): int
    {
        return $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()));
    }

    private function district(string $ags, string $name, object $area, bool $withGeometry): void
    {
        \assert($area instanceof \App\Allocation\Domain\Entity\DispatchArea);
        $district = new District($ags, $name)->setDispatchArea($area);
        $this->em()->persist($district);
        $geometry = new DistrictGeometry($district);
        if ($withGeometry) {
            $geometry->storeGeometry('{"type":"Polygon","coordinates":[]}', 'test', null, null, null);
        }
        $this->em()->persist($geometry);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
