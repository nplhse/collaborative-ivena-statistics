<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern\Package;

use App\DataFixtures\Pattern\Application\DefaultPatternPackage;
use App\DataFixtures\Pattern\Package\PatternPackage;
use App\DataFixtures\Pattern\Package\PatternPackageWriter;
use App\DataFixtures\Pattern\Package\SegmentPattern;
use Doctrine\Bundle\FixturesBundle\Loader\SymfonyFixturesLoader;
use Doctrine\Bundle\FixturesBundle\Purger\ORMPurgerFactory;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * `app:patterns:write-defaults` and `app:patterns:validate` against the synthetic reference catalog.
 */
#[ResetDatabase]
final class PatternPackageCommandsTest extends KernelTestCase
{
    private string $directory;

    #[\Override]
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/pattern-packages-'.bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
        parent::tearDown();
    }

    public function testTheWrittenDefaultsPassTheCatalogCheck(): void
    {
        $application = new Application(self::bootKernel());
        $this->loadReferenceCatalog();
        $file = $this->directory.'/defaults.json';

        $write = new CommandTester($application->find('app:patterns:write-defaults'));
        self::assertSame(Command::SUCCESS, $write->execute(['--output' => $file]));
        self::assertFileExists($file);

        $validate = new CommandTester($application->find('app:patterns:validate'));
        self::assertSame(Command::SUCCESS, $validate->execute(['file' => $file]), $validate->getDisplay());
        self::assertStringContainsString('is a valid pattern package.', $validate->getDisplay());

        self::assertSame(Command::FAILURE, $write->execute(['--output' => $file]), 'an existing file is kept without --force');
    }

    public function testAValueOutsideTheCatalogFailsTheCatalogCheck(): void
    {
        $application = new Application(self::bootKernel());
        $this->loadReferenceCatalog();
        $file = $this->directory.'/unknown-occasion.json';

        $defaults = DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00'));
        $segments = $defaults->segments;
        $rural = $segments['rural-basic'];
        $occasions = [];
        foreach ($rural->occasions as $name => $share) {
            $occasions['Verkehrsunfall' === $name ? 'Mondfahrt' : $name] = $share;
        }
        $segments['rural-basic'] = new SegmentPattern($rural->indications, $occasions, $rural->assignments, $rural->infections, $rural->secondaryTransports, $rural->transportTypes, $rural->hours, $rural->flags, $rural->transportTimeMinutes);
        /** @var PatternPackageWriter $writer */
        $writer = self::getContainer()->get(PatternPackageWriter::class);
        $writer->write($file, $writer->seal(new PatternPackage($defaults->meta, $segments, $defaults->indicationGroups, $defaults->fallbackGroup)));

        $validate = new CommandTester($application->find('app:patterns:validate'));
        self::assertSame(Command::FAILURE, $validate->execute(['file' => $file]));
        self::assertStringContainsString('cannot serve it', $validate->getDisplay());
        self::assertStringContainsString('Mondfahrt', $validate->getDisplay());

        self::assertSame(Command::SUCCESS, $validate->execute(['file' => $file, '--no-catalog' => true]), 'the format itself is valid');
    }

    private function loadReferenceCatalog(): void
    {
        /** @var ContainerInterface $container */
        $container = self::getContainer()->get('test.service_container'); // @phpstan-ignore symfonyContainer.serviceNotFound
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $container->get(EntityManagerInterface::class);
        /** @var SymfonyFixturesLoader $loader */
        $loader = $container->get('doctrine.fixtures.loader');
        /** @var ORMPurgerFactory $purgerFactory */
        $purgerFactory = $container->get('doctrine.fixtures.purger.orm_purger_factory');
        new ORMExecutor($entityManager, $purgerFactory->createForEntityManager('default', $entityManager, []))->execute($loader->getFixtures(['reference']), true);
    }
}
