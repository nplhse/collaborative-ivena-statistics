<?php

declare(strict_types=1);

namespace App\Tests\Statistics\Integration\Mapping;

use App\Statistics\Application\Mapping\HourOfDayValueMapper;
use App\Statistics\Application\Mapping\WeekdayValueMapper;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DistributionValueMapperTranslationIntegrationTest extends KernelTestCase
{
    public function testHourSlotResolvesIntlPlaceholderFromMessagesCatalog(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');

        $mapper = new HourOfDayValueMapper($translator);

        self::assertSame('07:00', $mapper->label(7));
        self::assertSame('23:00', $mapper->label(23));
    }

    public function testWeekdayUsesCatalogEntries(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get('translator');

        $mapper = new WeekdayValueMapper($translator);

        self::assertSame('Monday', $mapper->label(1));
        self::assertSame('Sunday', $mapper->label(7));
    }
}
