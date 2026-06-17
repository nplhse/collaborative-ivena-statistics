<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Reference;

use App\Tests\DataFixtures\Reference\Support\CreatesReferenceYamlLoader;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ReferenceYamlLoaderTest extends TestCase
{
    use CreatesReferenceYamlLoader;

    #[Test]
    public function areasReturnsExpectedRowsInOrder(): void
    {
        $rows = $this->referenceYamlLoader()->areas();

        self::assertSame(['state' => 'Hessen', 'name' => 'Bergstraße'], $rows[0] ?? null);
        self::assertSame(['state' => 'Bayern', 'name' => 'Bayerischer Untermain'], $rows[\count($rows) - 1] ?? null);

        self::assertTrue($this->containsAreaPair($rows, 'Hessen', 'Darmstadt'));
        self::assertTrue($this->containsAreaPair($rows, 'Hessen', 'Offenbach'));
        self::assertTrue($this->containsAreaPair($rows, 'Hessen', 'Wiesbaden'));

        $keys = \array_map(static fn (array $row): string => $row['state'].'|'.$row['name'], $rows);
        self::assertSame($keys, \array_values(\array_unique($keys)));
        self::assertCount(25, $rows);
    }

    #[Test]
    public function assignmentsReturnsExpectedNamesInOrder(): void
    {
        $values = $this->referenceYamlLoader()->names('assignments.yaml');

        self::assertSame('Arzt/Arzt', $values[0] ?? null);
        self::assertSame('ZLST', $values[\count($values) - 1] ?? null);
        self::assertContains('Einweisung', $values);
        self::assertContains('Patient', $values);
        self::assertSame($values, \array_values(\array_unique($values)));
        self::assertCount(7, $values);
    }

    #[Test]
    public function departmentsReturnsExpectedNamesInOrder(): void
    {
        $values = $this->referenceYamlLoader()->names('departments.yaml');

        self::assertSame('Akut- und Gerontopsych. / Isolierung', $values[0] ?? null);
        self::assertContains('Kardiologie', $values);
        self::assertContains('Nuklearmedizin', $values);
        self::assertSame('Zu- Verlegung Sonderlage Ukraine', $values[\count($values) - 1] ?? null);
        self::assertSame($values, \array_values(\array_unique($values)));
        self::assertGreaterThanOrEqual(108, \count($values));
    }

    #[Test]
    public function specialitiesReturnsExpectedNamesInOrder(): void
    {
        $values = $this->referenceYamlLoader()->names('specialities.yaml');

        self::assertSame('Augenheilkunde', $values[0] ?? null);
        self::assertContains('Innere Medizin', $values);
        self::assertContains('Neurologie', $values);
        self::assertSame('Zentrale Notaufnahme', $values[\count($values) - 1] ?? null);
        self::assertSame($values, \array_values(\array_unique($values)));
        self::assertGreaterThanOrEqual(20, \count($values));
    }

    #[Test]
    public function infectionsReturnsExpectedNamesInOrder(): void
    {
        $values = $this->referenceYamlLoader()->names('infections.yaml');

        self::assertSame('3MRGN', $values[0] ?? null);
        self::assertSame('Varizellen', $values[\count($values) - 1] ?? null);
        self::assertContains('MRSA', $values);
        self::assertContains('Influenza', $values);
        self::assertContains('V.a. COVID', $values);
        self::assertSame($values, \array_values(\array_unique($values)));
        self::assertCount(19, $values);
    }

    #[Test]
    public function occasionsReturnsExpectedNamesInOrder(): void
    {
        $values = $this->referenceYamlLoader()->names('occasions.yaml');

        self::assertSame('Arbeitsunfall', $values[0] ?? null);
        self::assertSame('Weaning', $values[\count($values) - 1] ?? null);
        self::assertContains('Verkehrsunfall', $values);
        self::assertContains('Sturz < 3m Höhe', $values);
        self::assertContains('Hausunfall', $values);
        self::assertSame($values, \array_values(\array_unique($values)));
        self::assertCount(29, $values);
    }

    #[Test]
    public function secondaryTransportsReturnsExpectedNamesInOrder(): void
    {
        $values = $this->referenceYamlLoader()->names('secondary_transports.yaml');

        self::assertSame('Diagnostik', $values[0] ?? null);
        self::assertSame('Kapazitätsengpass', $values[\count($values) - 1] ?? null);
        self::assertContains('OP', $values);
        self::assertContains('Sekundärverlegung', $values);
        self::assertContains('Weaning', $values);
        self::assertSame($values, \array_values(\array_unique($values)));
        self::assertCount(8, $values);
    }

    #[Test]
    public function hospitalsReturnsExpectedRowsInOrder(): void
    {
        $rows = $this->referenceYamlLoader()->hospitals();

        self::assertSame('Agaplesion Bethanien Krankenhaus', $rows[0]['name'] ?? null);
        self::assertSame(
            'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            $rows[\count($rows) - 1]['name'] ?? null,
        );

        $allNames = \array_map(static fn (array $row): mixed => $row['name'] ?? null, $rows);
        self::assertContains('Klinikum Kassel', $allNames);
        self::assertContains('St. Josefs-Hospital Wiesbaden', $allNames);

        $first = $rows[0];
        self::assertSame('Hessen', $first['state']);
        self::assertSame('Frankfurt', $first['area']);
        self::assertIsArray($first['address']);
        self::assertSame('Deutschland', $first['address']['country']);
        self::assertCount(77, $rows);
    }

    #[Test]
    public function indicationsNormalizedReturnsExpectedRowsInOrder(): void
    {
        $values = $this->referenceYamlLoader()->indicationsNormalized();

        self::assertSame(['code' => '000', 'name' => 'Kein Patient vorhanden'], $values[0] ?? null);
        self::assertSame(['code' => '809', 'name' => 'Allgemeinmedizin, sonstiger Notfall'], $values[\count($values) - 1] ?? null);

        self::assertTrue($this->containsIndicationPair($values, '271', 'Extremitäten offen'));
        self::assertTrue($this->containsIndicationPair($values, '393', 'Hypoglykämie'));
        self::assertTrue($this->containsIndicationPair($values, '715', 'Katheterwechsel (transurethral)'));

        $keys = \array_map(
            static fn (array $row): string => $row['code'].'|'.$row['name'],
            $values,
        );
        self::assertSame($keys, \array_values(\array_unique($keys)));
        self::assertCount(210, $values);
    }

    #[Test]
    public function indicationsRawReturnsExpectedRowsInOrder(): void
    {
        $values = $this->referenceYamlLoader()->indicationsRaw();

        self::assertSame(['code' => '111', 'name' => 'primäre Todesfeststellung'], $values[0] ?? null);
        self::assertSame(['code' => '770', 'name' => 'sonstige Notfallsituation'], $values[\count($values) - 1] ?? null);

        self::assertTrue($this->containsIndicationPair($values, '323', 'Hypertonie'));
        self::assertTrue($this->containsIndicationPair($values, '431', 'Akute Suizidalität'));
        self::assertTrue($this->containsIndicationPair($values, '721', 'Augenverletzung mit Fremdkörper'));

        $keys = \array_map(
            static fn (array $row): string => $row['code'].'|'.($row['name'] ?? ''),
            $values,
        );
        self::assertSame($keys, \array_values(\array_unique($keys)));
        self::assertCount(200, $values);
    }

    #[Test]
    public function indicationGroupsReturnsExpectedDefinitionsInOrder(): void
    {
        $groups = $this->referenceYamlLoader()->indicationGroups();

        self::assertSame('Akute Dyspnoe & respiratorische Notfälle', $groups[0]['name'] ?? null);
        self::assertSame('Verbrennung & Umweltmedizin', $groups[\count($groups) - 1]['name'] ?? null);
        self::assertContains('Brustschmerz & akutes Koronarsyndrom', array_map(static fn (array $row): string => $row['name'], $groups));
        self::assertFalse(
            array_any($groups, static fn (array $row): bool => str_contains((string) $row['name'], 'Anaphylax')),
        );
        self::assertCount(20, $groups);
    }

    /**
     * @param list<array{state: string, name: string}> $rows
     */
    private function containsAreaPair(array $rows, string $state, string $name): bool
    {
        return array_any($rows, fn (array $row): bool => $row['state'] === $state && $row['name'] === $name);
    }

    /**
     * @param list<array{code: string, name: string}> $values
     */
    private function containsIndicationPair(array $values, string $code, string $name): bool
    {
        return array_any($values, fn (array $row): bool => $row['code'] === $code && $row['name'] === $name);
    }
}
