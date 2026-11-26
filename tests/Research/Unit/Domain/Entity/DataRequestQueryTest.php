<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Domain\Entity;

use App\Research\Application\Export\HospitalMinimum;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestQuery;
use App\Research\Domain\Entity\DataRequestQueryVersion;
use App\Research\Domain\Enum\QueryKind;
use App\Research\Domain\Exception\DataRequestException;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Export queries and their versions on a data extract request (ADR 045); a request holds several queries, each one file.
 */
final class DataRequestQueryTest extends TestCase
{
    public function testARequestHoldsSeveralQueriesWithTheirOwnVersions(): void
    {
        $request = $this->request();
        $author = new User();
        $at = new \DateTimeImmutable('2026-12-01 09:00');

        $byMonth = $request->addQuery('by_month', '  Allocations per month.  ', QueryKind::Aggregated, ' SELECT 1 ', $author, $at);
        $cases = $request->addQuery('cases', 'Single cases.', QueryKind::SingleCases, 'SELECT 2', null, $at);
        $byMonth->revise('SELECT 3', $author, new \DateTimeImmutable('2026-12-02 09:00'));

        self::assertSame([$byMonth, $cases], $request->getQueries());
        self::assertSame($cases, $request->findQuery('cases'));
        self::assertNull($request->findQuery('missing'));
        self::assertSame($request, $byMonth->getRequest());
        self::assertSame('by_month', $byMonth->getName());
        self::assertSame('Allocations per month.', $byMonth->getDescription());
        self::assertSame(QueryKind::SingleCases, $cases->getKind());
        self::assertEquals($at, $byMonth->getCreatedAt());
        self::assertNull($byMonth->getId());

        self::assertSame([1, 2], array_map(static fn (DataRequestQueryVersion $version): int => $version->getNumber(), $byMonth->getVersions()));
        $first = $byMonth->getVersions()[0];
        self::assertSame('SELECT 1', $first->getSql(), 'the SQL is trimmed');
        self::assertSame(hash('sha256', 'SELECT 1'), $first->getChecksum());
        self::assertSame($author, $first->getCreatedBy());
        self::assertEquals($at, $first->getCreatedAt());
        self::assertSame($byMonth, $first->getQuery());
        self::assertNull($first->getId());
        self::assertSame('SELECT 3', $byMonth->getCurrentVersion()->getSql());
        self::assertCount(1, $cases->getVersions());
    }

    public function testAVersionPassesOnlyAfterACheckWithoutViolations(): void
    {
        $version = $this->request()->addQuery('q', 'Description', QueryKind::Aggregated, 'SELECT 1', null, new \DateTimeImmutable('2026-12-01'))->getCurrentVersion();
        self::assertFalse($version->passesCheck(), 'not checked yet');
        self::assertNull($version->getCheckedAt());
        self::assertNull($version->getDryRun());

        $violation = ['code' => 'table', 'parameters' => ['table' => 'user']];
        $version->recordCheck([$violation], new \DateTimeImmutable('2026-12-01 10:00'));
        self::assertFalse($version->passesCheck());
        self::assertSame([$violation], $version->getViolations());

        $version->recordCheck([], new \DateTimeImmutable('2026-12-01 11:00'));
        $version->recordDryRun(['rows' => 3]);
        self::assertTrue($version->passesCheck());
        self::assertEquals(new \DateTimeImmutable('2026-12-01 11:00'), $version->getCheckedAt());
        self::assertSame(['rows' => 3], $version->getDryRun());
    }

    public function testInvalidQueriesAreRefused(): void
    {
        $request = $this->request();
        $at = new \DateTimeImmutable('2026-12-01');
        $request->addQuery('taken', 'Description', QueryKind::Aggregated, 'SELECT 1', null, $at);

        foreach ([
            'taken name' => static fn (): DataRequestQuery => $request->addQuery('taken', 'Description', QueryKind::Aggregated, 'SELECT 1', null, $at),
            'uppercase name' => static fn (): DataRequestQuery => $request->addQuery('ByMonth', 'Description', QueryKind::Aggregated, 'SELECT 1', null, $at),
            'empty description' => static fn (): DataRequestQuery => $request->addQuery('a', '  ', QueryKind::Aggregated, 'SELECT 1', null, $at),
            'empty SQL' => static fn (): DataRequestQuery => $request->addQuery('b', 'Description', QueryKind::Aggregated, '  ', null, $at),
            'too long SQL' => static fn (): DataRequestQuery => $request->addQuery('c', 'Description', QueryKind::Aggregated, str_repeat('x', DataRequestQueryVersion::MAX_SQL_LENGTH + 1), null, $at),
        ] as $case => $step) {
            try {
                $step();
                self::fail($case.' was accepted.');
            } catch (DataRequestException) {
                $this->addToAssertionCount(1);
            }
        }

        self::assertCount(1, $request->getQueries());
    }

    public function testHospitalIdsAreReadFromTheReservedColumn(): void
    {
        self::assertSame([1, 2, 3], HospitalMinimum::parse('{1,2,3,2}'));
        self::assertSame([7], HospitalMinimum::parse(7));
        self::assertSame([], HospitalMinimum::parse('{}'));
        self::assertSame([], HospitalMinimum::parse(null));
    }

    private function request(): DataRequest
    {
        return new DataRequest(new User(), new DataRequestContent(
            title: 'Title',
            summary: 'Summary',
            institution: 'University',
            enrichmentPlanned: false,
            purpose: 'Purpose',
            periodFrom: new \DateTimeImmutable('2024-01-01'),
            periodTo: new \DateTimeImmutable('2024-12-31'),
            population: 'All allocations',
            variables: 'Month',
            granularity: 'Month',
            recipients: 'Team',
            enrichmentDetails: null,
            singleCases: false,
            description: 'Description',
            dataNeeds: 'Data needs',
            responsiblePersons: 'Dr. A',
            conflictsOfInterest: 'none',
            ethics: 'Not needed',
        ), new \DateTimeImmutable('2026-11-30 09:00'));
    }
}
