<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application;

use App\Allocation\Domain\Entity\Department;
use App\DataFixtures\Pattern\Application\PatternLookupResolver;

/**
 * Answers whether a department was closed at a time, for planned closures of one hospital.
 */
final readonly class ClosedDepartments
{
    /**
     * @param array<int, list<PlannedClosure>> $byDepartmentId
     */
    private function __construct(
        private array $byDepartmentId,
    ) {
    }

    /**
     * @param list<PlannedClosure> $closures
     */
    public static function of(array $closures, PatternLookupResolver $lookups): self
    {
        $byDepartmentId = [];
        foreach ($closures as $closure) {
            $byDepartmentId[$lookups->resolveId(Department::class, $closure->department)][] = $closure;
        }

        return new self($byDepartmentId);
    }

    public function isClosed(int $departmentId, \DateTimeImmutable $at): bool
    {
        foreach ($this->byDepartmentId[$departmentId] ?? [] as $closure) {
            if ($at >= $closure->startsAt && $at < $closure->endsAt) {
                return true;
            }
        }

        return false;
    }
}
