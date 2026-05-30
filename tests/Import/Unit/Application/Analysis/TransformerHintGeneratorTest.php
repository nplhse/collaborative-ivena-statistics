<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Application\Analysis;

use App\Import\Application\Analysis\TransformerHintGenerator;
use PHPUnit\Framework\TestCase;

final class TransformerHintGeneratorTest extends TestCase
{
    private TransformerHintGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new TransformerHintGenerator();
    }

    public function testReferenceNotFoundHint(): void
    {
        $hint = $this->generator->generate(
            'speciality',
            'Innere Medizin',
            'REF_NOT_FOUND | Reference not found for "speciality"',
        );

        self::assertSame(
            "Add mapping/normalizer for field 'speciality' value 'Innere Medizin'",
            $hint,
        );
    }

    public function testEmptyValueHint(): void
    {
        $hint = $this->generator->generate(
            'createdAt',
            '(empty)',
            'createdAt: This value should not be blank.',
        );

        self::assertSame("Handle empty value for field 'createdAt'", $hint);
    }

    public function testDateFormatHint(): void
    {
        $hint = $this->generator->generate(
            'createdAt',
            '31.13.2024',
            'INVALID_DATE: Expected format d.m.Y',
        );

        self::assertSame('Add parser/normalizer for date/number format', $hint);
    }

    public function testEnumHint(): void
    {
        $hint = $this->generator->generate(
            'gender',
            'X',
            'INVALID_ENUM for gender',
        );

        self::assertSame("Add enum mapping/normalizer for field 'gender' value 'X'", $hint);
    }

    public function testDefaultHint(): void
    {
        $hint = $this->generator->generate(
            '(unknown)',
            '',
            'Unable to detect a supported row type.',
        );

        self::assertSame('Inspect reject reason and add transformer if appropriate', $hint);
    }
}
