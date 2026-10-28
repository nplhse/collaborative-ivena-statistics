<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern\Package;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Invalid packages fail before generation, with errors that name the table and the key (#883).
 */
final class PatternPackageReaderTest extends PatternPackageTestCase
{
    public function testItRefusesAnotherFormatOrVersion(): void
    {
        $data = $this->defaultsData();
        $data['meta']['version'] = 2;

        self::assertSame(
            ['meta' => 'Unsupported package: expected format "ivena-distribution-patterns" version 1, found format "ivena-distribution-patterns" version 2.'],
            $this->violations($data),
        );
    }

    public function testItRefusesInvalidJson(): void
    {
        $result = $this->reader()->readJson('{"meta": ');

        self::assertFalse($result->isValid());
        self::assertStringStartsWith('Invalid JSON', $result->violations[0]->message);
    }

    public function testItRefusesAMissingFile(): void
    {
        $result = $this->reader()->read('/nonexistent/patterns.json');

        self::assertStringStartsWith('File not found', $result->violations[0]->message);
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    #[DataProvider('invalidPackages')]
    public function testItNamesTheTableAndTheKey(\Closure $change, string $path, string $message): void
    {
        $violations = $this->violations($change($this->defaultsData()));

        self::assertArrayHasKey($path, $violations, 'found: '.implode(', ', array_keys($violations)));
        self::assertStringContainsString($message, $violations[$path]);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): array<string, mixed>, string, string}>
     */
    public static function invalidPackages(): iterable
    {
        // phpcs-like brevity: each closure changes one thing in the decoded code defaults.
        yield 'unknown key' => [
            static function (array $data): array {
                $data['segments']['urban-full']['weather'] = ['sun' => 1.0];

                return $data;
            },
            'segments.urban-full.weather', 'This field was not expected.',
        ];
        yield 'missing key' => [
            static function (array $data): array {
                unset($data['indication_groups']['332']['female']);

                return $data;
            },
            'indication_groups.332.female', 'This field is missing.',
        ];
        yield 'share out of range' => [
            static function (array $data): array {
                $data['segments']['urban-basic']['occasions']['Verkehrsunfall'] = 1.5;

                return $data;
            },
            'segments.urban-basic.occasions.Verkehrsunfall', 'between 0 and 1',
        ];
        yield 'text instead of a number' => [
            static function (array $data): array {
                $data['indication_groups']['5']['female'] = '0.45';

                return $data;
            },
            'indication_groups.5.female', 'should be of type',
        ];
        yield 'null' => [
            static function (array $data): array {
                $data['segments']['rural-basic']['hours']['3'] = null;

                return $data;
            },
            'segments.rural-basic.hours.3', 'should not be null',
        ];
        yield 'shares do not sum to 1' => [
            static function (array $data): array {
                $data['indication_groups']['332']['departments']['cardiology'] = 0.8;

                return $data;
            },
            'indication_groups.332.departments', 'Shares must sum to 1, they sum to 0.8.',
        ];
        yield 'department by catalog name' => [
            static function (array $data): array {
                $data['indication_groups']['332']['departments'] = ['Kardiologie' => 1.0];

                return $data;
            },
            'indication_groups.332.departments.Kardiologie', 'Departments are keyed by the generator key, here "cardiology", not by a catalog name.',
        ];
        yield 'unknown department key' => [
            static function (array $data): array {
                $data['indication_groups']['332']['departments'] = ['moon-medicine' => 1.0];

                return $data;
            },
            'indication_groups.332.departments.moon-medicine', 'Unknown department key.',
        ];
        yield 'missing segment' => [
            static function (array $data): array {
                unset($data['segments']['rural-basic']);

                return $data;
            },
            'segments', 'Segment "rural-basic" is missing',
        ];
        yield 'unknown segment' => [
            static function (array $data): array {
                $data['segments']['rural-full'] = $data['segments']['urban-full'];

                return $data;
            },
            'segments.rural-full', 'Unknown segment "rural-full"',
        ];
        yield 'PZC code with two digits' => [
            static function (array $data): array {
                $data['segments']['urban-full']['indications']['shares']['32'] = $data['segments']['urban-full']['indications']['shares']['322'];
                unset($data['segments']['urban-full']['indications']['shares']['322']);

                return $data;
            },
            'segments.urban-full.indications.shares.32', 'Expected a three-digit PZC code.',
        ];
        yield 'unknown transport type' => [
            static function (array $data): array {
                $data['segments']['urban-full']['transport_types'] = ['G' => 0.95, 'S' => 0.05];

                return $data;
            },
            'segments.urban-full.transport_types.S', 'Unknown transport type',
        ];
        yield 'hour 24' => [
            static function (array $data): array {
                $data['segments']['urban-full']['hours']['24'] = $data['segments']['urban-full']['hours']['0'];
                unset($data['segments']['urban-full']['hours']['0']);

                return $data;
            },
            'segments.urban-full.hours.24', 'Expected an hour from 0 to 23.',
        ];
        yield 'unknown flag' => [
            static function (array $data): array {
                $data['segments']['urban-full']['flags']['is_drunk'] = 0.1;

                return $data;
            },
            'segments.urban-full.flags.is_drunk', 'Unknown flag.',
        ];
        yield 'resuscitation flag in a group' => [
            static function (array $data): array {
                $data['indication_groups']['332']['flags']['requires_resus'] = 0.5;

                return $data;
            },
            'indication_groups.332.flags.requires_resus', 'resus_factor',
        ];
        yield 'urgency 4' => [
            static function (array $data): array {
                $data['indication_groups']['1']['urgency'] = ['4' => 1.0];

                return $data;
            },
            'indication_groups.1.urgency.4', 'Expected an urgency of 1, 2 or 3.',
        ];
        yield 'age range upside down' => [
            static function (array $data): array {
                $data['indication_groups']['5']['ages'] = ['17-0' => 1.0];

                return $data;
            },
            'indication_groups.5.ages.17-0', 'min ≤ max ≤ 120',
        ];
        yield 'prefix starting with 0' => [
            static function (array $data): array {
                $data['indication_groups']['01'] = $data['indication_groups']['1'];

                return $data;
            },
            'indication_groups.01', 'not starting with 0',
        ];
        yield 'percentiles decrease' => [
            static function (array $data): array {
                $data['segments']['rural-basic']['transport_time_minutes']['p75'] = 12.0;

                return $data;
            },
            'segments.rural-basic.transport_time_minutes.p75', 'must not decrease',
        ];
        yield 'fallback group missing' => [
            static function (array $data): array {
                $data['fallback_group'] = '9';

                return $data;
            },
            'fallback_group', 'Group "9" is not in indication_groups.',
        ];
        yield 'wrong count' => [
            static function (array $data): array {
                $data['meta']['counts']['indications'] = 54;

                return $data;
            },
            'meta.counts.indications', 'Count is 54, but the package has 53.',
        ];
        yield 'bad source' => [
            static function (array $data): array {
                $data['meta']['source'] = 'Prod Export';

                return $data;
            },
            'meta.source', 'lowercase letters, digits and dashes',
        ];
    }

    public function testItRefusesAChangedFileByItsChecksum(): void
    {
        $data = $this->defaultsData();
        $data['segments']['urban-full']['occasions']['Verkehrsunfall'] = 0.14;
        $data['segments']['urban-full']['occasions']['Arbeitsunfall'] = 0.06;

        self::assertSame(
            ['meta.checksum.value' => 'Checksum does not match the content. The file was changed or truncated after it was written.'],
            $this->violations($data, reseal: false),
        );
    }
}
