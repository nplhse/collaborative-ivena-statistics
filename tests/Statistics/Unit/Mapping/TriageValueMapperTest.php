<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\TriageValueMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TriageValueMapperTest extends TestCase
{
    #[DataProvider('cases')]
    public function testLabel(?int $code, string $expectedKey): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with($expectedKey)
            ->willReturn($expectedKey);

        $mapper = new TriageValueMapper($translator);

        self::assertSame($expectedKey, $mapper->label($code));
    }

    /**
     * @return iterable<array{0:int|null,1:string}>
     */
    public static function cases(): iterable
    {
        yield [1, 'label.urgency.emergency'];
        yield [2, 'label.urgency.inpatient'];
        yield [3, 'label.urgency.outpatient'];
        yield [99, 'statistics.distribution.unknown_code'];
        yield [null, 'statistics.distribution.unknown_code'];
    }
}
