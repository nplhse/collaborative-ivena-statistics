<?php

declare(strict_types=1);

namespace App\Storage\Application\Capacity;

use App\Storage\Application\Contract\CapacityProviderInterface;
use App\Storage\Application\StorageSettings;
use App\Storage\Domain\Enum\CapacitySource;

/**
 * The configured budget, compared with the measured application usage.
 *
 * @psalm-suppress UnusedClass Tagged via CapacityProviderInterface.
 */
final readonly class BudgetCapacityProvider implements CapacityProviderInterface
{
    public function __construct(
        private StorageSettings $settings,
    ) {
    }

    #[\Override]
    public function source(): CapacitySource
    {
        return CapacitySource::Budget;
    }

    #[\Override]
    public function provide(?int $measuredUsedBytes, ?FilesystemSpace $filesystem): ?CapacityReference
    {
        if (null === $this->settings->budgetBytes) {
            return null;
        }

        return new CapacityReference(CapacitySource::Budget, $this->settings->budgetBytes, $measuredUsedBytes);
    }
}
