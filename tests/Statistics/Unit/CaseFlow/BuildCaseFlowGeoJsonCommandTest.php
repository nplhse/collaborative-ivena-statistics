<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\CaseFlow;

use App\Statistics\CaseFlow\Infrastructure\GeoJson\HessenDispatchAreaGeoJsonBuilder;
use App\Statistics\CaseFlow\UI\Console\Command\BuildCaseFlowGeoJsonCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

final class BuildCaseFlowGeoJsonCommandTest extends TestCase
{
    private const string PROJECT = __DIR__.'/../../../..';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/build-case-flow-geojson-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        foreach ([
            'config/case_flow/dispatch_area_geo_sources.yaml',
            'assets/geo/.source/hessen-kreise-raw.geojson',
            'assets/geo/.source/neighbour-kreise-raw.geojson',
        ] as $file) {
            $filesystem->copy(self::PROJECT.'/'.$file, $this->projectDir.'/'.$file);
        }
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    public function testBuildWritesTheCommittedMapAsset(): void
    {
        $output = new BufferedOutput();
        $command = new BuildCaseFlowGeoJsonCommand(new HessenDispatchAreaGeoJsonBuilder(), $this->projectDir);

        $exitCode = $command(new SymfonyStyle(new ArrayInput([]), $output));

        self::assertSame(Command::SUCCESS, $exitCode);
        // 24 Hessian and 5 neighbouring areas; merged: Kassel, Offenbach, Schweinfurt, Bayerischer Untermain.
        self::assertStringContainsString('Wrote 29 dispatch-area features (4 merged)', $output->fetch());
        self::assertFileEquals(
            self::PROJECT.'/assets/geo/hessen-landkreise.geojson',
            $this->projectDir.'/assets/geo/hessen-landkreise.geojson',
            'assets/geo/hessen-landkreise.geojson is out of date; run app:statistics:case-flow:build-geojson',
        );
    }
}
