<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Unit\SummarizedReport;

use App\Statistics\Application\SummarizedReport\ReportBuildResult;
use App\Statistics\Application\SummarizedReport\ReportTypeInterface;
use App\Statistics\Application\SummarizedReport\ReportTypeRegistry;
use PHPUnit\Framework\TestCase;

final class ReportTypeRegistryTest extends TestCase
{
    public function testGetOrFirstFallsBackToFirstRegisteredType(): void
    {
        $monthly = $this->type('monthly');
        $registry = new ReportTypeRegistry([$monthly]);

        self::assertSame($monthly, $registry->getOrFirst('unknown'));
        self::assertSame([$monthly], $registry->all());
    }

    private function type(string $key): ReportTypeInterface
    {
        $type = $this->createStub(ReportTypeInterface::class);
        $type->method('key')->willReturn($key);
        $type->method('labelTranslationKey')->willReturn('label.'.$key);
        $type->method('descriptionTranslationKey')->willReturn('description.'.$key);
        $type->method('build')->willReturn(new ReportBuildResult('template.twig', new \stdClass()));
        $type->method('supports')->willReturn(true);

        return $type;
    }
}
