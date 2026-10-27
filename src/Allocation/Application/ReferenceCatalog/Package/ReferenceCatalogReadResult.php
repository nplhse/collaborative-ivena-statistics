<?php

declare(strict_types=1);

namespace App\Allocation\Application\ReferenceCatalog\Package;

final readonly class ReferenceCatalogReadResult
{
    /**
     * @param list<ReferenceCatalogViolation> $violations
     */
    private function __construct(
        public ?ReferenceCatalogPackage $package,
        public array $violations,
    ) {
    }

    public static function valid(ReferenceCatalogPackage $package): self
    {
        return new self($package, []);
    }

    /**
     * @param non-empty-list<ReferenceCatalogViolation> $violations
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
