<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\Mapping;

use App\Statistics\Application\Mapping\TransportTimeBucketValueMapper;
use App\Statistics\Application\Panel\Distribution\TransportTimeBucketExpression;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class TransportTimeBucketValueMapperTest extends TestCase
{
    public function testLabelMapsBucketCodesToTranslationKeys(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->expects(self::once())
            ->method('trans')
            ->with('statistics.distribution.transport_time_bucket.10_20')
            ->willReturn('10–20 min');

        $mapper = new TransportTimeBucketValueMapper($translator);

        self::assertSame('10–20 min', $mapper->label(TransportTimeBucketExpression::MIN_10_TO_20));
    }
}
