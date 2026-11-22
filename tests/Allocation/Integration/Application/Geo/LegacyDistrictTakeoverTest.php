<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Geo;

use App\Allocation\Application\Geo\LegacyDistrictTakeover;
use App\Allocation\Application\Geo\LegacyDistrictTakeoverOutcome;
use App\Allocation\Domain\Entity\DispatchArea;
use App\Allocation\Domain\Entity\District;
use App\Allocation\Domain\Entity\DistrictGeometry;
use App\Allocation\Domain\Entity\DistrictLegacyTakeover;
use App\Allocation\Infrastructure\Factory\DispatchAreaFactory;
use App\Allocation\UI\Console\Command\ImportLegacyDistrictsCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class LegacyDistrictTakeoverTest extends KernelTestCase
{
    use Factories;

    private const array NORTH = ['type' => 'Polygon', 'coordinates' => [[[8.0, 51.0], [8.1, 51.0], [8.1, 51.1], [8.0, 51.0]]]];
    private const array SOUTH = ['type' => 'Polygon', 'coordinates' => [[[8.0, 50.0], [8.1, 50.0], [8.1, 50.1], [8.0, 50.0]]]];
    private const array CITY = ['type' => 'Polygon', 'coordinates' => [[[8.2, 50.0], [8.3, 50.0], [8.3, 50.1], [8.2, 50.0]]]];

    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        self::bootKernel();
        $this->file = (string) tempnam(sys_get_temp_dir(), 'legacy-districts');
        file_put_contents($this->file, json_encode([
            'type' => 'FeatureCollection',
            'areas' => [
                ['name' => 'North', 'ags' => ['06001'], 'geometry_sha256' => LegacyDistrictTakeover::geometryHash([self::NORTH])],
                ['name' => 'South', 'ags' => ['06002', '06003'], 'geometry_sha256' => LegacyDistrictTakeover::geometryHash([self::SOUTH, self::CITY])],
            ],
            'features' => [
                $this->feature('06001', 'North district', 2023, self::NORTH),
                $this->feature('06002', 'South district', 2023, self::SOUTH),
                $this->feature('06003', 'South city', 2025, self::CITY),
            ],
        ], \JSON_THROW_ON_ERROR));
    }

    #[\Override]
    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testTakesOverAndVerifiesAnAreaWithSeveralDistricts(): void
    {
        $south = $this->areas()['South'];

        $report = $this->takeover()->import();

        self::assertSame(LegacyDistrictTakeoverOutcome::Done, $report->outcome);
        self::assertSame(3, $report->created);
        self::assertSame(3, $report->geometriesStored);
        self::assertSame([], $report->problems);
        self::assertSame($south, $this->district('06003')->getDispatchArea()?->getId());

        $geometry = $this->em()->find(DistrictGeometry::class, $this->district('06003')->getId());
        self::assertInstanceOf(DistrictGeometry::class, $geometry);
        self::assertSame('legacy_vg250', $geometry->getGeometryProvider());
        self::assertSame('dl-de/by-2-0', $geometry->getGeometryLicence());
        self::assertSame('© BKG (2025) dl-de/by-2-0', $geometry->getGeometryAttribution());
        self::assertNull($geometry->getGeometryFetchedAt());

        self::assertSame(LegacyDistrictTakeoverOutcome::Done, $this->takeover()->verify()->outcome);
        $marker = $this->em()->find(DistrictLegacyTakeover::class, DistrictLegacyTakeover::SINGLE_ROW_ID);
        self::assertSame(hash_file('sha256', $this->file), $marker?->getTransferFileHash());
    }

    public function testARepeatedRunFinishesAnAbortedOne(): void
    {
        $this->areas();
        $this->em()->persist(new District('06002', 'Partly done'));
        $this->em()->flush();

        $report = $this->takeover()->import();

        self::assertSame(2, $report->created);
        self::assertSame(1, $report->assigned);
        self::assertSame(3, $report->geometriesStored);
        self::assertSame('Partly done', $this->district('06002')->getName());

        $again = $this->takeover()->import();
        self::assertSame(0, $again->created + $again->assigned + $again->geometriesStored);
        self::assertSame([], $again->problems);
        self::assertSame(LegacyDistrictTakeoverOutcome::Done, $this->takeover()->verify()->outcome);
    }

    public function testKeepsMaintainedDataAndFailsTheVerification(): void
    {
        $areas = $this->areas();
        $em = $this->em();
        $moved = new District('06001', 'Moved')->setDispatchArea($em->getReference(DispatchArea::class, $areas['South']));
        $maintained = new District('06002', 'Maintained');
        $maintainedGeometry = new DistrictGeometry($maintained);
        $maintainedGeometry->storeGeometry((string) json_encode(self::CITY), 'bkg', null, null, null);
        $em->persist($moved);
        $em->persist($maintained);
        $em->persist($maintainedGeometry);
        $em->flush();

        $report = $this->takeover()->import();

        self::assertCount(2, $report->problems);
        self::assertSame($areas['South'], $this->district('06001')->getDispatchArea()?->getId());
        $kept = $this->em()->find(DistrictGeometry::class, $this->district('06002')->getId());
        self::assertInstanceOf(DistrictGeometry::class, $kept);
        self::assertSame('bkg', $kept->getGeometryProvider());
        self::assertSame((string) json_encode(self::CITY), $kept->getGeometry());

        self::assertSame(LegacyDistrictTakeoverOutcome::Failed, $this->takeover()->verify()->outcome);
        self::assertNull($this->em()->find(DistrictLegacyTakeover::class, DistrictLegacyTakeover::SINGLE_ROW_ID));
    }

    public function testVerificationFailsOnAMissingGeometry(): void
    {
        $this->areas();
        $this->takeover()->import();
        $this->em()->getConnection()->executeStatement('UPDATE district_geometry SET geometry = NULL WHERE district_id = ?', [$this->district('06003')->getId()]);

        $report = $this->takeover()->verify();

        self::assertSame(LegacyDistrictTakeoverOutcome::Failed, $report->outcome);
        self::assertSame(['"South": a district has no geometry.'], $report->problems);
    }

    public function testAPartialSetOfAreasWritesNothing(): void
    {
        DispatchAreaFactory::createOne(['name' => 'North']);

        $report = $this->takeover()->import();

        self::assertSame(LegacyDistrictTakeoverOutcome::Failed, $report->outcome);
        self::assertSame(['Dispatch area "South" is missing.'], $report->problems);
        self::assertSame(0, $this->em()->getRepository(District::class)->count([]));
    }

    public function testAnInstallationWithoutLegacyAreasHasNothingToTakeOver(): void
    {
        DispatchAreaFactory::createOne(['name' => 'Elsewhere']);

        self::assertSame(LegacyDistrictTakeoverOutcome::NothingToTakeOver, $this->takeover()->import()->outcome);
        self::assertSame(LegacyDistrictTakeoverOutcome::NothingToTakeOver, $this->takeover()->verify()->outcome);
        self::assertNull($this->em()->find(DistrictLegacyTakeover::class, DistrictLegacyTakeover::SINGLE_ROW_ID));
    }

    public function testLaterMaintenanceCannotFailACompletedTakeover(): void
    {
        $areas = $this->areas();
        $this->takeover()->import();
        $this->takeover()->verify();
        $this->district('06001')->setDispatchArea($this->em()->getReference(DispatchArea::class, $areas['South']));
        $this->em()->flush();

        self::assertSame(LegacyDistrictTakeoverOutcome::AlreadyComplete, $this->takeover()->import()->outcome);
        self::assertSame(LegacyDistrictTakeoverOutcome::AlreadyComplete, $this->takeover()->verify()->outcome);
    }

    public function testCommandFailsOnAFailedVerification(): void
    {
        DispatchAreaFactory::createOne(['name' => 'North']);

        self::assertSame(Command::FAILURE, $this->executeCommand(false, true));
    }

    public function testCommandTakesOverAndVerifies(): void
    {
        $this->areas();

        self::assertSame(Command::SUCCESS, $this->executeCommand(false, false));
        self::assertSame(Command::SUCCESS, $this->executeCommand(false, true));
        self::assertSame(Command::SUCCESS, $this->executeCommand(false, true));
    }

    public function testCommandHasNothingToDoWithoutLegacyAreas(): void
    {
        self::assertSame(Command::SUCCESS, $this->executeCommand(false, false));
    }

    public function testCommandSkipsTheDemoEnvironment(): void
    {
        $this->areas();

        self::assertSame(Command::SUCCESS, $this->executeCommand(true, false));
        self::assertSame(0, $this->em()->getRepository(District::class)->count([]));
    }

    /**
     * @param array<string, mixed> $geometry
     *
     * @return array<string, mixed>
     */
    private function feature(string $ags, string $name, int $vintage, array $geometry): array
    {
        return [
            'type' => 'Feature',
            'properties' => ['ags' => $ags, 'name' => $name, 'kind' => 'Landkreis', 'vintage' => $vintage],
            'geometry' => $geometry,
        ];
    }

    /**
     * @return array{North: int, South: int}
     */
    private function areas(): array
    {
        return [
            'North' => (int) DispatchAreaFactory::createOne(['name' => 'North'])->getId(),
            'South' => (int) DispatchAreaFactory::createOne(['name' => 'South'])->getId(),
        ];
    }

    private function district(string $ags): District
    {
        $district = $this->em()->getRepository(District::class)->findOneBy(['ags' => $ags]);
        self::assertInstanceOf(District::class, $district);

        return $district;
    }

    private function takeover(): LegacyDistrictTakeover
    {
        return new LegacyDistrictTakeover($this->em(), $this->file);
    }

    private function executeCommand(bool $demoMode, bool $verify): int
    {
        $command = new ImportLegacyDistrictsCommand($this->takeover(), $demoMode);

        return $command(new SymfonyStyle(new ArrayInput([]), new BufferedOutput()), $verify);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
