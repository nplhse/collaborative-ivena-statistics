<?php

declare(strict_types=1);

namespace App\DataFixtures\Pattern\Package;

final readonly class PatternPackageReadResult
{
    /**
     * @param list<PatternPackageViolation> $violations
     */
    private function __construct(
        public ?PatternPackage $package,
        public array $violations,
    ) {
    }

    public static function valid(PatternPackage $package): self
    {
        return new self($package, []);
    }

    /**
     * @param non-empty-list<PatternPackageViolation> $violations
     */
    public static function invalid(array $violations): self
    {
        return new self(null, $violations);
    }

    /**
     * @phpstan-assert-if-true !null $this->package
     */
    public function isValid(): bool
    {
        return [] === $this->violations;
    }
}
