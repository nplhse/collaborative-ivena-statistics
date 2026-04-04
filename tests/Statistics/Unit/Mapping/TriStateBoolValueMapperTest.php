<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\TriStateBoolValueMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TriStateBoolValueMapperTest extends TestCase
{
    public function testLabelSuffixes(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::exactly(3))
            ->method('trans')
            ->willReturnCallback(static fn (string $id): string => $id);

        $mapper = new TriStateBoolValueMapper($translator, 'statistics.distribution.tri.requires_resus');

        self::assertSame('statistics.distribution.tri.requires_resus.unknown', $mapper->label(0));
        self::assertSame('statistics.distribution.tri.requires_resus.no', $mapper->label(1));
        self::assertSame('statistics.distribution.tri.requires_resus.yes', $mapper->label(2));
    }
}
