<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit\Audit;

use App\Allocation\Domain\Entity\State;
use App\Shared\Infrastructure\Audit\AuditFieldRules;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

final class AuditFieldRulesTest extends TestCase
{
    private AuditFieldRules $rules;

    #[\Override]
    protected function setUp(): void
    {
        $this->rules = new AuditFieldRules();
    }

    public function testIsClassAuditedTrueForAuditedEntity(): void
    {
        self::assertTrue($this->rules->isClassAudited(User::class));
        self::assertTrue($this->rules->isClassAudited(State::class));
    }

    public function testIsClassAuditedFalseWithoutAttribute(): void
    {
        self::assertFalse($this->rules->isClassAudited(NonAuditedDummy::class));

        /** @var class-string $nonexistentFqcn */
        $nonexistentFqcn = 'NonExistingClass';
        self::assertFalse($this->rules->isClassAudited($nonexistentFqcn));
    }

    public function testShouldSkipAutomaticMetadataFields(): void
    {
        self::assertTrue($this->rules->shouldSkipField(User::class, 'createdAt'));
        self::assertTrue($this->rules->shouldSkipField(User::class, 'updatedBy'));
    }

    public function testIsSensitiveFieldForUserPassword(): void
    {
        self::assertTrue($this->rules->isSensitiveField(User::class, 'password'));
        self::assertFalse($this->rules->isSensitiveField(User::class, 'email'));
    }
}

final class NonAuditedDummy
{
}
