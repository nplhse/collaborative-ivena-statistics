<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\IndicationCompare;

use App\Statistics\Application\IndicationCompare\IndicationCompareSubjectRequestParser;
use App\Statistics\Application\IndicationDashboard\IndicationSubjectType;
use App\Statistics\UI\Http\Navigation\StatisticsQueryKeys;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class IndicationCompareSubjectRequestParserTest extends TestCase
{
    private IndicationCompareSubjectRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IndicationCompareSubjectRequestParser();
    }

    public function testParseNewSubjectParameters(): void
    {
        $request = Request::create('/statistics/indication/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'single',
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            StatisticsQueryKeys::SUBJECT_B_TYPE => 'group',
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
        ]);

        $pair = $this->parser->parse($request);

        self::assertNotNull($pair);
        self::assertSame(IndicationSubjectType::Single, $pair->typeA);
        self::assertSame(12, $pair->idA);
        self::assertSame(IndicationSubjectType::Group, $pair->typeB);
        self::assertSame(3, $pair->idB);
    }

    public function testParseLegacyIndicationParameters(): void
    {
        $request = Request::create('/statistics/indication/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::INDICATION_A => '5',
            StatisticsQueryKeys::INDICATION_B => '8',
        ]);

        $pair = $this->parser->parse($request);

        self::assertNotNull($pair);
        self::assertSame(IndicationSubjectType::Single, $pair->typeA);
        self::assertSame(5, $pair->idA);
        self::assertSame(IndicationSubjectType::Single, $pair->typeB);
        self::assertSame(8, $pair->idB);
    }

    public function testNewParametersTakePrecedenceOverLegacy(): void
    {
        $request = Request::create('/statistics/indication/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'group',
            StatisticsQueryKeys::SUBJECT_A_ID => '2',
            StatisticsQueryKeys::SUBJECT_B_TYPE => 'group',
            StatisticsQueryKeys::SUBJECT_B_ID => '4',
            StatisticsQueryKeys::INDICATION_A => '99',
            StatisticsQueryKeys::INDICATION_B => '100',
        ]);

        $pair = $this->parser->parse($request);

        self::assertNotNull($pair);
        self::assertSame(IndicationSubjectType::Group, $pair->typeA);
        self::assertSame(2, $pair->idA);
        self::assertSame(IndicationSubjectType::Group, $pair->typeB);
        self::assertSame(4, $pair->idB);
    }

    public function testReturnsNullWhenIncomplete(): void
    {
        $request = Request::create('/statistics/indication/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'single',
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
        ]);

        self::assertNull($this->parser->parse($request));
    }

    public function testReturnsNullForInvalidType(): void
    {
        $request = Request::create('/statistics/indication/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'invalid',
            StatisticsQueryKeys::SUBJECT_A_ID => '12',
            StatisticsQueryKeys::SUBJECT_B_TYPE => 'single',
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
        ]);

        self::assertNull($this->parser->parse($request));
    }

    public function testReturnsNullForNonNumericId(): void
    {
        $request = Request::create('/statistics/indication/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'single',
            StatisticsQueryKeys::SUBJECT_A_ID => 'abc',
            StatisticsQueryKeys::SUBJECT_B_TYPE => 'single',
            StatisticsQueryKeys::SUBJECT_B_ID => '3',
        ]);

        self::assertNull($this->parser->parse($request));
    }

    public function testIsSameSubjectDetectsIdenticalPair(): void
    {
        $request = Request::create('/statistics/indication/compare', Request::METHOD_GET, [
            StatisticsQueryKeys::SUBJECT_A_TYPE => 'group',
            StatisticsQueryKeys::SUBJECT_A_ID => '7',
            StatisticsQueryKeys::SUBJECT_B_TYPE => 'group',
            StatisticsQueryKeys::SUBJECT_B_ID => '7',
        ]);

        $pair = $this->parser->parse($request);

        self::assertNotNull($pair);
        self::assertTrue($pair->isSameSubject());
    }
}
