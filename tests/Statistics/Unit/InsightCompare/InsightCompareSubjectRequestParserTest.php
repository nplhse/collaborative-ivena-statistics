<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\InsightCompare;

use App\Statistics\Application\InsightCompare\InsightCompareSubjectRequestParser;
use App\Statistics\Application\Insights\InsightDimensionKey;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InsightCompareSubjectRequestParserTest extends TestCase
{
    private InsightCompareSubjectRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new InsightCompareSubjectRequestParser();
    }

    public function testParseCanonicalDimensionParameters(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => 'indications',
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            StatisticsQueryKeys::SUBJECT_B_DIMENSION => 'departments',
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
        ]);

        $pair = $this->parser->parse($request);

        self::assertNotNull($pair);
        self::assertSame(InsightDimensionKey::Indications, $pair->dimensionA);
        self::assertSame(12, $pair->idA);
        self::assertSame(InsightDimensionKey::Departments, $pair->dimensionB);
        self::assertSame(3, $pair->idB);
    }

    public function testParseLegacyTypeParameters(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'single',
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            StatisticsQueryKeys::SUBJECT_B_TYPE => 'group',
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
        ]);

        $pair = $this->parser->parse($request);

        self::assertNotNull($pair);
        self::assertSame(InsightDimensionKey::Indications, $pair->dimensionA);
        self::assertSame(12, $pair->idA);
        self::assertSame(InsightDimensionKey::IndicationGroups, $pair->dimensionB);
        self::assertSame(3, $pair->idB);
    }

    public function testParseLegacyIndicationParameters(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::INDICATION_A => '5',
            StatisticsQueryKeys::INDICATION_B => '8',
        ]);

        $pair = $this->parser->parse($request, InsightDimensionKey::Indications);

        self::assertNotNull($pair);
        self::assertSame(InsightDimensionKey::Indications, $pair->dimensionA);
        self::assertSame(5, $pair->idA);
        self::assertSame(InsightDimensionKey::Indications, $pair->dimensionB);
        self::assertSame(8, $pair->idB);
    }

    public function testParseIdsWithPathDimensionFallback(): void
    {
        $request = Request::create('/statistics/insights/assignments/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_ID => '9',
            StatisticsQueryKeys::SUBJECT_B_ID => '11',
        ]);

        $pair = $this->parser->parse($request, InsightDimensionKey::Assignments);

        self::assertNotNull($pair);
        self::assertSame(InsightDimensionKey::Assignments, $pair->dimensionA);
        self::assertSame(9, $pair->idA);
        self::assertSame(InsightDimensionKey::Assignments, $pair->dimensionB);
        self::assertSame(11, $pair->idB);
    }

    public function testCanonicalizeQueryMapsLegacyTypes(): void
    {
        $query = $this->parser->canonicalizeQuery([
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'group',
            StatisticsQueryKeys::SUBJECT_A_ID => '2',
            StatisticsQueryKeys::SUBJECT_B_TYPE => 'single',
            StatisticsQueryKeys::SUBJECT_B_ID => '4',
            'scope' => 'public',
        ], InsightDimensionKey::Indications);

        self::assertSame('indication-groups', $query[StatisticsQueryKeys::SUBJECT_A_DIMENSION]);
        self::assertSame(2, $query[StatisticsQueryKeys::SUBJECT_A_ID]);
        self::assertSame('indications', $query[StatisticsQueryKeys::SUBJECT_B_DIMENSION]);
        self::assertSame(4, $query[StatisticsQueryKeys::SUBJECT_B_ID]);
        self::assertArrayNotHasKey(StatisticsQueryKeys::SUBJECT_A_TYPE, $query);
        self::assertSame('public', $query['scope']);
    }

    public function testReturnsNullWhenIncomplete(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => 'indications',
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
        ]);

        self::assertNull($this->parser->parse($request));
    }

    public function testIsSameSubjectDetectsIdenticalPair(): void
    {
        $request = Request::create('/statistics/insights/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_DIMENSION => 'indication-groups',
            StatisticsQueryKeys::SUBJECT_A_ID => '7',
            StatisticsQueryKeys::SUBJECT_B_DIMENSION => 'indication-groups',
            StatisticsQueryKeys::SUBJECT_B_ID => '7',
        ]);

        $pair = $this->parser->parse($request);

        self::assertNotNull($pair);
        self::assertTrue($pair->isSameSubject());
    }

    public function testCanonicalizeQueryMapsLegacyIndicationKeysWithPathDimension(): void
    {
        $query = $this->parser->canonicalizeQuery([
            StatisticsQueryKeys::INDICATION_A => '5',
            StatisticsQueryKeys::INDICATION_B => '8',
            'scope' => 'public',
        ], InsightDimensionKey::Indications);

        self::assertSame('indications', $query[StatisticsQueryKeys::SUBJECT_A_DIMENSION]);
        self::assertSame(5, $query[StatisticsQueryKeys::SUBJECT_A_ID]);
        self::assertSame('indications', $query[StatisticsQueryKeys::SUBJECT_B_DIMENSION]);
        self::assertSame(8, $query[StatisticsQueryKeys::SUBJECT_B_ID]);
        self::assertArrayNotHasKey(StatisticsQueryKeys::INDICATION_A, $query);
        self::assertArrayNotHasKey(StatisticsQueryKeys::INDICATION_B, $query);
    }
}
