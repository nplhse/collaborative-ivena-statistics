<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Functional\Command;

use App\Allocation\Domain\Enum\IndicationRawReviewStatus;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
final class AuditIndicationReviewCommandTest extends KernelTestCase
{
    use Factories;

    public function testSucceedsWhenNoFailChecksReportIssues(): void
    {
        IndicationRawFactory::createOne([
            'code' => 88001,
            'name' => 'Clean Raw',
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No FAIL checks reported issues.', $tester->getDisplay());
        self::assertStringContainsString('Total raw indications', $tester->getDisplay());
    }

    public function testFailsWhenTargetIsSetButStatusIsNotMatched(): void
    {
        $normalized = IndicationNormalizedFactory::createOne(['code' => 88002, 'name' => 'Norm']);
        IndicationRawFactory::createOne([
            'code' => 88002,
            'name' => 'Inconsistent Raw',
            'target' => $normalized,
            'reviewStatus' => IndicationRawReviewStatus::Unreviewed,
        ]);

        $tester = $this->commandTester();
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Target set but status is not matched', $tester->getDisplay());
        self::assertStringContainsString('FAIL', $tester->getDisplay());
    }

    public function testJsonOptionReturnsStructuredResults(): void
    {
        IndicationRawFactory::createOne(['code' => 88003, 'name' => 'Json Raw']);

        $tester = $this->commandTester();
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);

        /** @var list<array{id: string, count: int}> $decoded */
        $decoded = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($decoded);
        self::assertSame('raw_total', $decoded[0]['id']);
        self::assertSame(1, $decoded[0]['count']);
    }

    public function testVerboseShowsSampleIdsForFailingCheck(): void
    {
        $normalized = IndicationNormalizedFactory::createOne(['code' => 88004, 'name' => 'Verbose Norm']);
        $raw = IndicationRawFactory::createOne([
            'code' => 88004,
            'name' => 'Verbose Raw',
            'target' => $normalized,
            'reviewStatus' => IndicationRawReviewStatus::NeedsReview,
        ]);

        $tester = $this->commandTester();
        $tester->execute(['--show-samples' => true]);

        self::assertStringContainsString('Sample IDs', $tester->getDisplay());
        self::assertStringContainsString('target_not_matched', $tester->getDisplay());
        self::assertStringContainsString((string) $raw->getId(), $tester->getDisplay());
    }

    private function commandTester(): CommandTester
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $command = $application->find('app:allocation:audit-indication-review');

        return new CommandTester($command);
    }
}
