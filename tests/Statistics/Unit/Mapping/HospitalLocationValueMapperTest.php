<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\HospitalLocationValueMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class HospitalLocationValueMapperTest extends TestCase
{
    #[DataProvider('cases')]
    public function testLabel(?int $code, string $expectedKey): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with($expectedKey)
            ->willReturn($expectedKey);

        $mapper = new HospitalLocationValueMapper($translator);

        self::assertSame($expectedKey, $mapper->label($code));
    }

    /**
     * @return iterable<array{0:int|null,1:string}>
     */
    public static function cases(): iterable
    {
        yield [1, 'hospital.location.Urban'];
        yield [2, 'hospital.location.Mixed'];
        yield [3, 'hospital.location.Rural'];
        yield [99, 'statistics.distribution.location_not_set'];
        yield [null, 'statistics.distribution.location_not_set'];
    }
}
