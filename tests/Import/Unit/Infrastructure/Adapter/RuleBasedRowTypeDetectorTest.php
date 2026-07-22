<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Infrastructure\Adapter;

use App\Import\Domain\Enum\AllocationRowType;
use App\Import\Infrastructure\Adapter\RuleBasedRowTypeDetector;
use PHPUnit\Framework\TestCase;

final class RuleBasedRowTypeDetectorTest extends TestCase
{
    private RuleBasedRowTypeDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new RuleBasedRowTypeDetector();
    }

    public function testDetectReturnsMciCaseWhenManvIsSet(): void
    {
        self::assertSame(AllocationRowType::MCI_CASE, $this->detector->detect(['manv' => 'yes']));
    }

    public function testDetectReturnsMciCaseWhenManvIdIsSet(): void
    {
        self::assertSame(AllocationRowType::MCI_CASE, $this->detector->detect(['manv_id' => '123']));
    }

    public function testDetectReturnsAllocationWhenPzcIsSet(): void
    {
        self::assertSame(AllocationRowType::ALLOCATION, $this->detector->detect(['pzc' => 'A1']));
    }

    public function testDetectReturnsNullForEmptyRow(): void
    {
        self::assertNull($this->detector->detect([]));
    }

    public function testDetectReturnsNullWhenManvIsWhitespaceOnly(): void
    {
        self::assertNull($this->detector->detect(['manv' => '   ']));
    }

    public function testDetectReturnsNullWhenPzcIsWhitespaceOnly(): void
    {
        self::assertNull($this->detector->detect(['pzc' => "  \t "]));
    }
}
