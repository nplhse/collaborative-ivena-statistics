<?php

declare(strict_types=1);

namespace App\Tests\User\Unit\Application\Backfill;

use App\User\Application\Backfill\UserCreatedAtBackfillDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Which date `app:user:backfill-created-at` takes for one user. Which imports count (completed and partial, not failed
 * or pending) is in `DoctrineUserCreatedAtBackfillImportSourceTest`, that only owned hospitals count (not access
 * grants) in `UserCreatedAtBackfillHospitalQueryTest`; dry run and apply over the console in
 * `BackfillUserCreatedAtCommandTest`.
 */
#[CoversClass(UserCreatedAtBackfillDecision::class)]
final class UserCreatedAtBackfillDecisionTest extends TestCase
{
    private const string CURRENT = '2026-08-13 14:20:00';

    /**
     * @return iterable<string, array{
     *     ?string,
     *     list<array{id: int, name: string}>,
     *     array<int, string>,
     *     'own'|'hospital'|null,
     *     ?string,
     *     ?string,
     *     bool
     * }>
     */
    public static function cases(): iterable
    {
        // own import, owned hospitals, first import per hospital id => source, candidate, hospital name, update?
        yield 'own import' => ['2022-05-10 09:42:00', [], [], 'own', '2022-05-10 09:42:00', null, true];
        yield 'hospital import' => [null, [['id' => 1, 'name' => 'Klinikum Musterstadt']], [1 => '2021-04-03 08:15:00'], 'hospital', '2021-04-03 08:15:00', 'Klinikum Musterstadt', true];
        yield 'own import beats an older hospital import' => ['2022-05-10 09:42:00', [['id' => 1, 'name' => 'Priority Klinik']], [1 => '2020-01-01 00:00:00'], 'own', '2022-05-10 09:42:00', null, true];
        yield 'earliest of several hospitals' => [null, [['id' => 1, 'name' => 'Hospital A'], ['id' => 2, 'name' => 'Hospital B']], [1 => '2023-01-01 10:00:00', 2 => '2020-08-15 07:30:00'], 'hospital', '2020-08-15 07:30:00', 'Hospital B', true];
        yield 'same date: lower hospital id' => [null, [['id' => 7, 'name' => 'Hospital G'], ['id' => 3, 'name' => 'Hospital C']], [3 => '2020-08-15 07:30:00', 7 => '2020-08-15 07:30:00'], 'hospital', '2020-08-15 07:30:00', 'Hospital C', true];
        yield 'hospital without import is skipped' => [null, [['id' => 1, 'name' => 'Empty Klinik'], ['id' => 2, 'name' => 'Import Klinik']], [2 => '2023-01-01 10:00:00'], 'hospital', '2023-01-01 10:00:00', 'Import Klinik', true];
        yield 'own import later than createdAt' => ['2027-01-01 00:00:00', [], [], 'own', '2027-01-01 00:00:00', null, false];
        yield 'own import at the same time' => [self::CURRENT, [], [], 'own', self::CURRENT, null, false];
        yield 'hospital import later than createdAt' => [null, [['id' => 1, 'name' => 'Later Klinik']], [1 => '2027-01-01 00:00:00'], 'hospital', '2027-01-01 00:00:00', 'Later Klinik', false];
        yield 'imports of hospitals the user does not own' => [null, [], [1 => '2020-01-01 00:00:00'], null, null, null, false];
        yield 'no import at all' => [null, [], [], null, null, null, false];
    }

    /**
     * @param list<array{id: int, name: string}> $ownedHospitals
     * @param array<int, string>                 $firstImportByHospital
     * @param 'own'|'hospital'|null              $source
     */
    #[DataProvider('cases')]
    public function testDecidesSourceDateAndWhetherToUpdate(
        ?string $firstOwnImport,
        array $ownedHospitals,
        array $firstImportByHospital,
        ?string $source,
        ?string $candidate,
        ?string $hospitalName,
        bool $shouldUpdate,
    ): void {
        $decision = UserCreatedAtBackfillDecision::decide(
            new \DateTimeImmutable(self::CURRENT),
            null === $firstOwnImport ? null : new \DateTimeImmutable($firstOwnImport),
            $ownedHospitals,
            array_map(static fn (string $value): \DateTimeImmutable => new \DateTimeImmutable($value), $firstImportByHospital),
        );

        self::assertSame(
            [$source, $candidate, $hospitalName, $shouldUpdate],
            [$decision->source, $decision->candidate?->format('Y-m-d H:i:s'), $decision->hospitalName, $decision->shouldUpdate],
        );
    }
}
