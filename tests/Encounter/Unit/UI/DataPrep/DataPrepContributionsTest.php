<?php

declare(strict_types=1);

namespace App\Tests\Encounter\Unit\UI\DataPrep;

use App\Encounter\Application\Dimension\EncounterDimensionRegistry;
use App\Encounter\Contract\DataPrep\DataPrepCatalog;
use App\Encounter\Contract\DataPrep\DataPrepContributionInterface;
use App\Encounter\Contract\DataPrep\DataPrepLocalMap;
use App\Encounter\UI\DataPrep\DataPrepContributions;
use App\Encounter\UI\DataPrep\HideDataPrepWithoutContributionsListener;
use App\Encounter\UI\Navigation\DataPrepToolProvider;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDataPrepContribution;
use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayDimension;
use App\Tests\Support\Encounter\Probe\ProbeDimension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The registry of data preparation contributions (#1301): order by schema, never by registration; validation of
 * what a contribution brings; no tool without contributions.
 */
final class DataPrepContributionsTest extends TestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/data-prep-contributions-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->directory.'/*') ?: []);
        rmdir($this->directory);
    }

    public function testContributionsAreOrderedBySchemaWhateverTheRegistrationOrder(): void
    {
        $probe = $this->contribution('probe', profiles: ['probe-b', 'probe-a']);
        $stay = new InpatientStayDataPrepContribution();

        foreach ([[$probe, $stay], [$stay, $probe]] as $registered) {
            $contributions = $this->registry($registered);
            self::assertSame(['inpatient-poc', 'probe-a', 'probe-b'], array_column($contributions->profiles(), 'id'));
            self::assertSame(['inpatient_stay', 'probe'], array_keys($contributions->schemas()));
        }
    }

    public function testTheSchemaGetsTheKeyRulesOfTheDimension(): void
    {
        $schemas = $this->registry([new InpatientStayDataPrepContribution()])->schemas();

        self::assertSame(new InpatientStayDimension()->keyRules(1)->toArray(), $schemas['inpatient_stay']['keys']);
        self::assertSame('stay_fingerprint', $schemas['inpatient_stay']['fields'][0]['name'] ?? null);
    }

    public function testASchemaFileWithItsOwnKeyRulesIsRefused(): void
    {
        $this->expectExceptionMessage('must not contain key rules');
        $this->registry([$this->contribution('probe', schemaExtra: ['keys' => []])])->schemas();
    }

    public function testAProfileOfAnotherSchemaAndDuplicateIdsAreRefused(): void
    {
        try {
            $this->registry([$this->contribution('probe', profiles: ['probe-a'], profileSchema: 'inpatient_stay')])->profiles();
            self::fail('A profile of another schema is refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('does not belong to schema "probe"', $exception->getMessage());
        }

        $this->expectExceptionMessage('Two data preparation profiles with id "inpatient-poc"');
        $this->registry([new InpatientStayDataPrepContribution(), $this->contribution('probe', profiles: ['inpatient-poc'])])->profiles();
    }

    public function testCatalogIdsAndLocalMapsAreUnique(): void
    {
        $catalog = new DataPrepCatalog('shared', [], 'route');
        try {
            $this->registry([$this->contribution('probe', catalogs: [$catalog]), $this->contribution('inpatient_stay', catalogs: [$catalog])])->catalogs();
            self::fail('Two catalogs with one id are refused.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('"shared"', $exception->getMessage());
        }

        $map = new DataPrepLocalMap('t', 'e');
        $this->expectExceptionMessage('Only one data preparation contribution may define a local map');
        $this->registry([$this->contribution('probe', localMap: $map), $this->contribution('inpatient_stay', localMap: $map)])->localMap();
    }

    public function testTwoContributionsForOneSchemaAreRefused(): void
    {
        $this->expectExceptionMessage('Two data preparation contributions for schema "inpatient_stay"');
        $this->registry([new InpatientStayDataPrepContribution(), new InpatientStayDataPrepContribution()]);
    }

    public function testWithoutContributionsTheToolEntryAndPagesAreGone(): void
    {
        $empty = $this->registry([]);
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);

        self::assertTrue($empty->isEmpty());
        self::assertSame([], new DataPrepToolProvider($checker, $empty)->tools());
        self::assertCount(1, new DataPrepToolProvider($checker, $this->registry([new InpatientStayDataPrepContribution()]))->tools());

        $listener = new HideDataPrepWithoutContributionsListener($empty);
        foreach (HideDataPrepWithoutContributionsListener::ROUTES as $route) {
            try {
                $listener($this->request($route));
                self::fail(sprintf('Route "%s" is hidden.', $route));
            } catch (NotFoundHttpException) {
            }
        }
        // The public update check stays for tools already downloaded; a tool with contributions stays reachable.
        $listener($this->request('app_encounter_data_prep_latest'));
        new HideDataPrepWithoutContributionsListener($this->registry([new InpatientStayDataPrepContribution()]))($this->request('app_tools_data_prep'));
    }

    /**
     * @param list<DataPrepContributionInterface> $contributions
     */
    private function registry(array $contributions): DataPrepContributions
    {
        return new DataPrepContributions($contributions, new EncounterDimensionRegistry([new InpatientStayDimension(), new ProbeDimension()]));
    }

    private function request(string $route): RequestEvent
    {
        $request = new Request();
        $request->attributes->set('_route', $route);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    /**
     * @param list<string>          $profiles
     * @param array<string, mixed>  $schemaExtra
     * @param list<DataPrepCatalog> $catalogs
     */
    private function contribution(string $schema, array $profiles = [], ?string $profileSchema = null, array $schemaExtra = [], array $catalogs = [], ?DataPrepLocalMap $localMap = null): DataPrepContributionInterface
    {
        $schemaFile = $this->directory.'/'.$schema.'.json';
        file_put_contents($schemaFile, json_encode(['schema' => $schema, 'version' => 1, 'fields' => [], ...$schemaExtra], \JSON_THROW_ON_ERROR));
        $profileFiles = [];
        foreach ($profiles as $id) {
            $profileFiles[] = $file = $this->directory.'/'.$schema.'-'.$id.'.profile.json';
            file_put_contents($file, json_encode(['id' => $id, 'schema' => $profileSchema ?? $schema], \JSON_THROW_ON_ERROR));
        }

        return new readonly class($schema, $schemaFile, $profileFiles, $catalogs, $localMap) implements DataPrepContributionInterface {
            /**
             * @param list<string>          $profileFiles
             * @param list<DataPrepCatalog> $catalogs
             */
            public function __construct(private string $schema, private string $schemaFile, private array $profileFiles, private array $catalogs, private ?DataPrepLocalMap $localMap)
            {
            }

            #[\Override]
            public function schema(): string
            {
                return $this->schema;
            }

            #[\Override]
            public function schemaFile(): string
            {
                return $this->schemaFile;
            }

            #[\Override]
            public function profileFiles(): array
            {
                return $this->profileFiles;
            }

            #[\Override]
            public function scriptFile(): ?string
            {
                return null;
            }

            #[\Override]
            public function catalogs(): array
            {
                return $this->catalogs;
            }

            #[\Override]
            public function localMap(): ?DataPrepLocalMap
            {
                return $this->localMap;
            }
        };
    }
}
