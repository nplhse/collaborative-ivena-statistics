<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Application;

use App\DataFixtures\Pattern\Package\PatternPackagePath;
use App\DataFixtures\Pattern\Package\PatternPackageReader;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Turns the pattern source setting into a pattern set (ADR 022).
 *
 * `defaults` (or empty) selects the code defaults; anything else is a package file: a file name in
 * `var/patterns/`, a path relative to the project or an absolute path. A package is read and validated
 * completely before it is used, so an invalid package stops a run before its first row.
 */
final class PatternSetLoader implements ResetInterface
{
    /** @var array<string, PatternSet> resolved path → set */
    private array $loaded = [];

    public function __construct(
        private readonly PatternPackageReader $reader,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @throws InvalidPatternPackageException when the package is missing or invalid
     */
    public function load(string $source): PatternSet
    {
        $source = trim($source);
        if ('' === $source || PatternSet::DEFAULTS === $source) {
            return PatternSet::defaults();
        }

        $path = PatternPackagePath::resolve($source, $this->projectDir);
        if (isset($this->loaded[$path])) {
            return $this->loaded[$path];
        }

        $result = $this->reader->read($path);
        if (!$result->isValid()) {
            throw new InvalidPatternPackageException($path, $result->violations);
        }

        return $this->loaded[$path] = PatternSet::fromPackage($result->package);
    }

    #[\Override]
    public function reset(): void
    {
        $this->loaded = [];
    }
}
