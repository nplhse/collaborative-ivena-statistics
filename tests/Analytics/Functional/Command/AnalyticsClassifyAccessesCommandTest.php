<?php

declare(strict_types=1);

namespace App\Tests\Analytics\Functional\Command;

use App\Analytics\Domain\AnalyticsCalendar;
use App\Analytics\Domain\Entity\AnalyticsRequest;
use App\Analytics\Domain\Enum\BrowserFamily;
use App\Analytics\Domain\Enum\DeviceType;
use App\Analytics\Domain\Enum\FeatureArea;
use App\Analytics\Infrastructure\Repository\AnalyticsRequestRepository;
use App\Analytics\UI\Console\Command\AnalyticsClassifyAccessesCommand;
use App\Tests\Support\Foundry\DatabaseKernelTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Tester\CommandTester;

final class AnalyticsClassifyAccessesCommandTest extends DatabaseKernelTestCase
{
    public function testPreviewsByDefaultAndClassifiesWithApply(): void
    {
        self::bootKernel();
        $repository = self::getContainer()->get(AnalyticsRequestRepository::class);
        foreach ([200, 404] as $status) {
            $repository->save(new AnalyticsRequest(
                occurredAt: AnalyticsCalendar::daysAgo(2)->setTime(9, 0),
                routeName: 'app_page_show',
                featureArea: FeatureArea::Pages,
                httpStatus: $status,
                isAuthenticated: false,
                userRole: null,
                analyticsUserKey: null,
                visitorKey: null,
                sessionKey: null,
                browserFamily: BrowserFamily::Firefox,
                deviceType: DeviceType::Desktop,
            ));
        }

        $tester = new CommandTester(self::getContainer()->get(AnalyticsClassifyAccessesCommand::class));

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Would classify 2 unclassified request(s); 1 of them count as accesses.', $this->display($tester));
        self::assertSame(2, (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM analytics_request WHERE counted_access IS NULL',
        ));

        $tester->execute(['--apply' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Classified 2 unclassified request(s); 1 of them count as accesses.', $this->display($tester));
        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM analytics_request WHERE counted_access IS NULL',
        ));
    }

    private function display(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
