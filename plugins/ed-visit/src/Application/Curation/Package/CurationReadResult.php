<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\Application\Curation\Package;

final readonly class CurationReadResult
{
    /**
     * @param list<CurationViolation> $violations
     */
    private function __construct(
        public ?CurationPackage $package,
        public array $violations,
    ) {
    }

    public static function valid(CurationPackage $package): self
    {
        return new self($package, []);
    }

    /**
     * @param non-empty-list<CurationViolation> $violations
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
