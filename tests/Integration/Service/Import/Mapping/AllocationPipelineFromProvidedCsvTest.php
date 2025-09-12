<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Import\Mapping;

use App\Service\Import\Adapter\SplCsvRowReader;
use App\Service\Import\Adapter\SplCsvStreamFactory;
use App\Service\Import\Charset\EncodingDetector;
use App\Service\Import\Mapping\AllocationRowMapper;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validation;

final class AllocationPipelineFromProvidedCsvTest extends KernelTestCase
{
    private string $fixtureFile = 'allocation_import_sample.csv';

    private ?string $fixturePath = null;

    private AllocationRowMapper $mapper;

    /** @var list<array<string,string>> */
    private array $rows = [];

    /** @var array<string,string> Baseline-Zeile aus der Fixture (erste Datenzeile) */
    private array $baselineRow = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $this->fixturePath = $projectDir.'/tests/Fixtures/'.$this->fixtureFile;

        self::assertFileExists($this->fixturePath, 'Fixture CSV missing at '.$this->fixturePath);

        $this->mapper = new AllocationRowMapper();
        $this->rows = $this->loadRows();

        self::assertNotEmpty($this->rows, 'Fixture has no data rows.');
        $this->baselineRow = $this->rows[0]; // erste Datenzeile als Baseline für Provider-Tests
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function loadRows(): array
    {
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);

        $reader = new SplCsvRowReader(
            new \SplFileObject($this->fixturePath, 'r'),
            new EncodingDetector(),
            new SplCsvStreamFactory($logger),
            encodingHint: 'UTF-8',
            delimiter: ';',
            enclosure: '"',
            escape: '\\',
        );

        return \iterator_to_array($reader->rowsAssoc(), preserve_keys: false);
    }

    /**
     * Validiert ein DTO (standalone Validator) und gibt die Violation-Messages zurück.
     *
     * @return list<string>
     */
    private function validateDto(object $dto): array
    {
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        $violations = $validator->validate($dto);

        $messages = [];
        foreach ($violations as $v) {
            $messages[] = sprintf('%s: %s', $v->getPropertyPath(), $v->getMessage());
        }

        return $messages;
    }

    /**
     * Führt Mapping + Validation für eine gegebene assoziative Zeile aus.
     *
     * @param array<string,string> $row
     *
     * @return array{0: object, 1: list<string>}
     */
    private function runPipeline(array $row): array
    {
        $dto = $this->mapper->mapAssoc($row);
        $violations = $this->validateDto($dto);

        return [$dto, $violations];
    }

    /**
     * Wie runPipeline(), aber mergen gezielte Overrides in eine Baseline-Zeile,
     * damit Pflichtspalten vorhanden sind (nützlich für DataProvider-Tests).
     *
     * @param array<string,?string> $overrides
     *
     * @return array{0: object, 1: list<string>}
     */
    private function runPipelineWithOverrides(array $overrides): array
    {
        $row = $this->baselineRow;
        foreach ($overrides as $k => $v) {
            $row[$k] = $v ?? ''; // fehlende Werte als leere Strings
        }

        return $this->runPipeline($row);
    }

    public function testRow1IsValidAndMapsFields(): void
    {
        [$dto, $violations] = $this->runPipeline($this->rows[0]);

        self::assertSame([], $violations);
        self::assertSame('07.01.2025 10:19', $dto->createdAt);
        self::assertSame('07.01.2025 13:14', $dto->arrivalAt);
        self::assertSame('F', $dto->gender);
        self::assertSame(74, $dto->age);
        self::assertSame('G', $dto->transportType);
    }

    public function testRow2GenderDBecomesXAndIsValid(): void
    {
        [$dto, $violations] = $this->runPipeline($this->rows[1]);

        self::assertSame([], $violations);
        self::assertSame('X', $dto->gender);
        self::assertSame('G', $dto->transportType);
        self::assertSame('02.03.2025 15:09', $dto->createdAt);
        self::assertSame('02.03.2025 16:43', $dto->arrivalAt);
    }

    public function testRow3InvalidAgeZeroButTransportIsMapped(): void
    {
        [$dto, $violations] = $this->runPipeline($this->rows[2]);

        self::assertNotEmpty($violations);
        self::assertTrue($this->containsField($violations, 'age'), 'Expected age violation');
        self::assertSame('G', $dto->transportType); // bleibt gültig
    }

    public function testRow4ValidCrossDayArrival(): void
    {
        [$dto, $violations] = $this->runPipeline($this->rows[3]);

        self::assertSame([], $violations);
        self::assertSame('08.01.2025 01:10', $dto->createdAt);
        self::assertSame('09.01.2025 01:12', $dto->arrivalAt);
        self::assertSame('A', $dto->transportType);
    }

    public function testRow5CreatedatPrefersErstellungsdatumAndIsValid(): void
    {
        [$dto, $violations] = $this->runPipeline($this->rows[4]);

        self::assertSame([], $violations);
        self::assertSame('11.02.2025 08:02', $dto->createdAt);
        self::assertSame('11.02.2025 09:33', $dto->arrivalAt);
        self::assertSame('G', $dto->transportType);
    }

    #[DataProvider('transportProvider')]
    public function testTransportVariants(?string $input, ?string $expected): void
    {
        // nur das Transportfeld überschreiben – Rest kommt aus Baseline-Zeile
        [$dto] = $this->runPipelineWithOverrides(['transportmittel' => $input]);

        self::assertSame($expected, $dto->transportType);
    }

    /**
     * @return iterable<array{0: string|null, 1: 'G'|'A'|null}>
     */
    public static function transportProvider(): iterable
    {
        yield 'Boden (lower)' => ['boden', 'G'];
        yield 'Boden (mixed)' => ['BoDeN', 'G'];
        yield 'Luft (upper)' => ['LUFT',  'A'];
        yield 'Alias RTW → Boden' => ['RTW',   'G'];
        yield 'Unknown → null' => ['Heli',  null];
        yield 'Empty → null' => ['',      null];
        yield 'Null → null' => [null,    null];
    }

    #[DataProvider('genderProvider')]
    public function testGenderVariants(?string $input, ?string $expected): void
    {
        // nur Geschlecht überschreiben – Rest aus Baseline-Zeile
        [$dto] = $this->runPipelineWithOverrides(['geschlecht' => $input]);

        self::assertSame($expected, $dto->gender);
    }

    /**
     * @return iterable<array{0: string|null, 1: 'M'|'F'|'X'}>
     */
    public static function genderProvider(): iterable
    {
        yield ['m', 'M'];
        yield ['w', 'F'];
        yield ['d', 'X'];
        yield ['x', 'X'];
        yield [null, 'X'];
        yield ['', 'X'];
    }

    /**
     * @param list<string> $violations
     */
    private function containsField(array $violations, string $field): bool
    {
        $needle = strtolower($field);
        foreach ($violations as $msg) {
            if (str_contains(strtolower($msg), $needle)) {
                return true;
            }
        }

        return false;
    }
}
