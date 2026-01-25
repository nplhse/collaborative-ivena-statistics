<?php

declare(strict_types=1);

namespace App\Tests\Seed\Functional\Command;

use App\Allocation\Domain\Entity\IndicationRaw;
use App\Allocation\Infrastructure\Factory\IndicationNormalizedFactory;
use App\Allocation\Infrastructure\Factory\IndicationRawFactory;
use App\Import\Infrastructure\Indication\IndicationKey;
use App\Seed\UI\Console\Command\SeedIndicationsCommand;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[CoversClass(SeedIndicationsCommand::class)]
final class SeedIndicationsCommandTest extends KernelTestCase
{
    use ResetDatabase;
    use Factories;

    protected function setUp(): void
    {
        parent::setUp();

        self::bootKernel();
    }

    private function runCommand(): CommandTester
    {
        /** @var SeedIndicationsCommand $cmd */
        $cmd = static::getContainer()->get(SeedIndicationsCommand::class);

        $tester = new CommandTester($cmd);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        return $tester;
    }

    public function testCreatesMissingRawAndLinksThem(): void
    {
        $user = UserFactory::createOne();

        $n1 = IndicationNormalizedFactory::createOne([
            'code' => 101,
            'name' => 'Synkope',
            'createdBy' => $user,
        ]);
        $n2 = IndicationNormalizedFactory::createOne([
            'code' => 102,
            'name' => 'Kollaps',
            'createdBy' => $user,
        ]);
        $n3 = IndicationNormalizedFactory::createOne([
            'code' => 103,
            'name' => 'Schwindel',
            'createdBy' => $user,
        ]);

        $output = $this->runCommand()->getDisplay();
        self::assertStringContainsString('New: 3', $output);
        self::assertStringContainsString('Updated: 0', $output);

        foreach ([$n1, $n2, $n3] as $n) {
            $hash = IndicationKey::hashFrom((string) $n->getCode(), $n->getName());

            /** @var IndicationRaw|null $raw */
            $raw = IndicationRawFactory::repository()->findOneBy(['hash' => $hash]);
            self::assertNotNull($raw, 'Raw not found by hash');
            self::assertSame($n->getId(), $raw->getTarget()?->getId());
            self::assertSame($n->getId(), $raw->getNormalized()?->getId());
            self::assertSame($n->getCode(), $raw->getCode());
            self::assertSame($n->getName(), $raw->getName());
            self::assertNotNull($raw->getCreatedBy());
        }
    }

    public function testIsIdempotentOnSecondRun(): void
    {
        $user = UserFactory::createOne();

        IndicationNormalizedFactory::createMany(2, function (int $i) use ($user) {
            $names = ['Asthma', 'COPD'];
            $name = $names[$i % count($names)];

            return [
                'code' => 200 + $i,
                'name' => $name,
                'createdBy' => $user,
            ];
        });

        $first = $this->runCommand()->getDisplay();
        self::assertStringContainsString('New: 2', $first);

        $second = $this->runCommand()->getDisplay();
        self::assertStringContainsString('New: 0', $second);
        self::assertStringContainsString('Updated: 0', $second);
        self::assertStringContainsString('Unchanged: 2', $second);
    }

    public function testFixesWrongLinksWhenRawExistsWithSameHash(): void
    {
        $user = UserFactory::createOne();

        $n = IndicationNormalizedFactory::createOne([
            'code' => 301,
            'name' => 'Appendizitis',
            'createdBy' => $user,
        ]);

        $hash = IndicationKey::hashFrom((string) $n->getCode(), $n->getName());

        $raw = IndicationRawFactory::createOne([
            'code' => $n->getCode(),
            'name' => $n->getName(),
            'hash' => $hash,
            'createdBy' => $user,
        ]);

        self::assertNull($raw->getTarget());
        self::assertNull($raw->getNormalized());

        $out = $this->runCommand()->getDisplay();
        self::assertStringContainsString('Updated: 1', $out);

        $reloaded = IndicationRawFactory::repository()->findOneBy(['hash' => $hash]);

        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->getTarget());
        self::assertNotNull($reloaded->getNormalized());

        $target = $reloaded->getTarget();
        $normalizedLink = $reloaded->getNormalized();

        self::assertSame($n->getId(), $target->getId());
        self::assertSame($n->getId(), $normalizedLink->getId());
    }
}
