<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Pattern\Package;

use App\DataFixtures\Pattern\Application\DefaultPatternPackage;
use App\DataFixtures\Pattern\Application\InvalidPatternPackageException;
use App\DataFixtures\Pattern\Application\PatternSet;
use App\DataFixtures\Pattern\Application\PatternSetLoader;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The pattern source setting: `defaults`, a file name in var/patterns/ or a path (#883).
 */
final class PatternSetLoaderTest extends PatternPackageTestCase
{
    private string $projectDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/pattern-loader-'.bin2hex(random_bytes(4));
    }

    #[\Override]
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    public function testDefaultsAndAnEmptySettingSelectTheCodeDefaults(): void
    {
        $loader = new PatternSetLoader($this->reader(), $this->projectDir);

        self::assertSame(PatternSet::DEFAULTS, $loader->load('defaults')->source);
        self::assertTrue($loader->load(' ')->isDefaults());
    }

    public function testAFileNameIsReadFromVarPatterns(): void
    {
        $package = $this->writer()->seal(DefaultPatternPackage::build(new \DateTimeImmutable('2026-10-01T00:00:00+00:00')));
        $this->writer()->write($this->projectDir.'/var/patterns/tuned.json', $package);
        $loader = new PatternSetLoader($this->reader(), $this->projectDir);

        $set = $loader->load('tuned.json');

        self::assertSame($package->meta->checksum->value, $set->source);
        self::assertSame($set, $loader->load($this->projectDir.'/var/patterns/tuned.json'), 'a package is read once per path');
    }

    public function testAnInvalidPackageFailsWithEveryProblem(): void
    {
        $data = $this->defaultsData();
        $data['indication_groups']['332']['departments'] = ['Kardiologie' => 1.0];
        $data['fallback_group'] = '9';
        new Filesystem()->dumpFile($this->projectDir.'/var/patterns/broken.json', $this->encode($data));
        $loader = new PatternSetLoader($this->reader(), $this->projectDir);

        try {
            $loader->load('broken.json');
            self::fail('an invalid package is refused');
        } catch (InvalidPatternPackageException $exception) {
            self::assertStringStartsWith($this->projectDir.'/var/patterns/broken.json is not a valid pattern package (2 problem(s)', $exception->getMessage());
            self::assertStringContainsString('indication_groups.332.departments.Kardiologie', $exception->getMessage());
            self::assertStringContainsString('fallback_group', $exception->getMessage());
        }
    }

    public function testAMissingFileFails(): void
    {
        $this->expectException(InvalidPatternPackageException::class);
        $this->expectExceptionMessage('File not found');

        new PatternSetLoader($this->reader(), $this->projectDir)->load('missing.json');
    }
}
