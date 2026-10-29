<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Unit\Infrastructure\Faker;

use App\Allocation\Infrastructure\Faker\Provider\Indication;
use App\Allocation\Infrastructure\Faker\Provider\IndicationFakerMethods;
use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialities;
use App\Allocation\Infrastructure\Faker\Provider\MedicalSpecialityFakerMethods;
use App\Allocation\Infrastructure\Faker\Provider\PzcCodes;
use App\Allocation\Infrastructure\Faker\Provider\SecondaryTransport;
use App\Allocation\Infrastructure\Faker\Provider\SecondaryTransportFakerMethods;
use Faker\Factory;
use Faker\Generator;
use PHPUnit\Framework\TestCase;

final class IndicationProviderTest extends TestCase
{
    private const string CURATED_HASHES = __DIR__.'/../../../../Support/ReferenceCatalog/curated-label-hashes.txt';

    public function testTheListHasAll208OfficialCodes(): void
    {
        $all = PzcCodes::all();

        self::assertCount(208, $all);
        self::assertCount(208, array_unique(array_column($all, 'code')));
        foreach ($all as $indication) {
            self::assertMatchesRegularExpression('/^\d{3}$/', $indication['code']);
            self::assertNotSame('', trim($indication['name']));
            self::assertNotSame('', PzcCodes::category($indication['code']));
        }

        self::assertContains(['code' => '000', 'name' => 'Kein Patient vorhanden'], $all);
        self::assertContains(['code' => '201', 'name' => 'KST-Chirurgie Zu-Verlegung Intensiv mit Arzt'], $all);
        self::assertContains(['code' => '530', 'name' => 'akute fetale Gefährdung, Erstversorgung ggf. in ungeeigneter Klinik'], $all);
    }

    public function testCategoriesFollowTheFirstDigit(): void
    {
        self::assertSame('Reanimation / ECMO', PzcCodes::category('143'));
        self::assertSame('Pädiatrie / Gynäkologie / Geburtshilfe', PzcCodes::category('526'));

        $this->expectException(\InvalidArgumentException::class);
        PzcCodes::category('');
    }

    public function testRawLabelsDifferFromTheOfficialWordingAndRepeatWithTheSeed(): void
    {
        $first = $this->rawLabels(5);

        foreach ($first as $code => $label) {
            self::assertNotSame(PzcCodes::name(substr($code, 1)), $label, $code);
        }
        self::assertSame($first, $this->rawLabels(5));
        self::assertNotSame($first, $this->rawLabels(6));
    }

    public function testUnmappedRawIndicationsCannotBeMapped(): void
    {
        /** @var Generator&IndicationFakerMethods $faker */
        $faker = $this->faker(1);

        for ($i = 0; $i < 50; ++$i) {
            $raw = $faker->unmappedRawIndication();
            if ('' === $raw['name']) {
                self::assertContains($raw['code'], array_column(PzcCodes::all(), 'code'));
            } else {
                self::assertNotContains($raw['code'], array_column(PzcCodes::all(), 'code'));
            }
        }
    }

    public function testEveryUnitBelongsToItsField(): void
    {
        self::assertSame('Innere Medizin', MedicalSpecialities::fieldOfUnit('Kardiologie'));
        self::assertSame('Pädiatrie', MedicalSpecialities::fieldOfUnit('Allgemeine Pädiatrie'));
        foreach (MedicalSpecialities::unitNames() as $unit) {
            self::assertContains(MedicalSpecialities::fieldOfUnit($unit), MedicalSpecialities::fieldNames(), $unit);
        }

        $this->expectException(\InvalidArgumentException::class);
        MedicalSpecialities::fieldOfUnit('Innere Medizin');
    }

    public function testDepartmentAliasesDifferFromTheirName(): void
    {
        /** @var Generator&MedicalSpecialityFakerMethods $faker */
        $faker = $this->faker(2);

        foreach (MedicalSpecialities::names() as $name) {
            $alias = $faker->departmentAlias($name);
            self::assertNotSame($name, $alias['name']);
            self::assertContains($alias['classification'], ['unambiguous_alias', 'faulty_catalog']);
        }
    }

    public function testNoGeneratedLabelIsACuratedLabel(): void
    {
        $curated = array_flip(array_values(array_filter(
            array_map(trim(...), (array) file(self::CURATED_HASHES)),
            static fn (string $line): bool => '' !== $line && !str_starts_with($line, '#'),
        )));
        self::assertGreaterThan(100, \count($curated));

        $generated = array_column(PzcCodes::all(), 'name');
        foreach ([1, 2, 3, 4, 5] as $seed) {
            array_push($generated, ...array_values($this->rawLabels($seed)));

            /** @var Generator&IndicationFakerMethods&MedicalSpecialityFakerMethods&SecondaryTransportFakerMethods $faker */
            $faker = $this->faker($seed);
            for ($i = 0; $i < 20; ++$i) {
                $generated[] = $faker->unmappedRawIndication()['name'];
                $generated[] = $faker->secondaryTransport();
            }
            foreach (MedicalSpecialities::names() as $name) {
                $generated[] = $name;
                $generated[] = $faker->departmentAlias($name)['name'];
            }
        }

        foreach ($generated as $label) {
            $hash = hash('sha256', mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $label))));
            self::assertArrayNotHasKey($hash, $curated, sprintf('"%s" is a curated label of the private catalog', $label));
        }
    }

    /**
     * @return array<string, string> code → generated label
     */
    private function rawLabels(int $seed): array
    {
        /** @var Generator&IndicationFakerMethods $faker */
        $faker = $this->faker($seed);
        $labels = [];
        foreach (PzcCodes::all() as $indication) {
            $labels['c'.$indication['code']] = $faker->rawIndicationLabel($indication['code']);
        }

        return $labels;
    }

    private function faker(int $seed): Generator
    {
        $faker = Factory::create('de_DE');
        $faker->addProvider(new Indication($faker));
        $faker->addProvider(new MedicalSpecialities($faker));
        $faker->addProvider(new SecondaryTransport($faker));
        // Free discarded generators first: Generator::__destruct() reseeds the global state at random (#891).
        gc_collect_cycles();
        $faker->seed($seed);

        return $faker;
    }
}
