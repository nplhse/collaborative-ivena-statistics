<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Unit\RequestTracking;

use App\Analytics\Application\RequestTracking\QueryParameterNameExtractor;
use PHPUnit\Framework\TestCase;

final class QueryParameterNameExtractorTest extends TestCase
{
    public function testExtractsNonEmptyNamesSortedUnique(): void
    {
        $extractor = new QueryParameterNameExtractor();

        $names = $extractor->extract([
            'urgency' => '1',
            'gender' => 'f',
            'empty' => '',
            'blank' => '   ',
            'shockRoom' => '1',
            'nested' => [],
            'list' => ['a'],
        ]);

        self::assertSame(['gender', 'list', 'shockRoom', 'urgency'], $names);
    }

    public function testSkipsEmptyNamesNullAndNestedEmptyArrays(): void
    {
        $extractor = new QueryParameterNameExtractor();

        $names = $extractor->extract([
            '' => 'ignored',
            'nullValue' => null,
            'nestedEmpty' => ['', null, []],
            'kept' => '1',
            'nestedKept' => ['', 'x'],
        ]);

        self::assertSame(['kept', 'nestedKept'], $names);
    }
}
