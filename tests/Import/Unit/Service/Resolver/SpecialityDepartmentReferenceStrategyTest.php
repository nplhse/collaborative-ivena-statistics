<?php

declare(strict_types=1);

namespace App\Tests\Import\Unit\Service\Resolver;

use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Infrastructure\Factory\DepartmentFactory;
use App\Import\Application\Exception\ReferenceNotFoundException;
use App\Import\Infrastructure\Resolver\Strategy\SpecialityDepartmentReferenceStrategy;
use App\User\Domain\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class SpecialityDepartmentReferenceStrategyTest extends KernelTestCase
{
    use ResetDatabase;

    private SpecialityDepartmentReferenceStrategy $strategy;

    protected function setUp(): void
    {
        self::bootKernel();

        UserFactory::createOne();
        DepartmentFactory::createOne(['name' => 'Geburtshilfe']);

        $this->strategy = self::getContainer()->get(SpecialityDepartmentReferenceStrategy::class);
        $this->strategy->warm();
    }

    #[DataProvider('obstetricsDepartmentAliasProvider')]
    public function testResolvesObstetricsDepartmentAliasesToGeburtshilfe(string $importValue): void
    {
        $allocation = new Allocation();

        $this->strategy->apply(
            $allocation,
            null,
            $importValue,
            false,
            static fn (?bool $v): bool => $v ?? false,
        );

        self::assertSame('Geburtshilfe', $allocation->getDepartment()?->getName());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function obstetricsDepartmentAliasProvider(): iterable
    {
        yield 'issue 125 Perinatalzentrum Level 1' => ['Perinatalzentrum Level 1'];
        yield 'issue 125 Perinataler Schwerpunkt' => ['Perinataler Schwerpunkt'];
        yield 'issue 125 Geburtsklinik' => ['Geburtsklinik'];
    }

    public function testUnknownDepartmentStillThrowsReferenceNotFoundException(): void
    {
        $allocation = new Allocation();

        $this->expectException(ReferenceNotFoundException::class);

        $this->strategy->apply(
            $allocation,
            null,
            'Unbekanntes Department',
            false,
            static fn (?bool $v): bool => $v ?? false,
        );
    }
}
