<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\HospitalTypeValueMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HospitalTypeValueMapperTest extends TestCase
{
    #[DataProvider('cases')]
    public function testLabel(?int $code, string $expectedKey): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with($expectedKey)
            ->willReturn($expectedKey);

        $mapper = new HospitalTypeValueMapper($translator);

        self::assertSame($expectedKey, $mapper->label($code));
    }

    /**
     * @return iterable<array{0:int|null,1:string}>
     */
    public static function cases(): iterable
    {
        yield [1, 'hospital.tier.Basic'];
        yield [2, 'hospital.tier.Extended'];
        yield [3, 'hospital.tier.Full'];
        yield [99, 'statistics.distribution.tier_not_set'];
        yield [null, 'statistics.distribution.tier_not_set'];
    }
}
