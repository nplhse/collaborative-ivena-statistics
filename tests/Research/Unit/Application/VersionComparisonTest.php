<?php

declare(strict_types=1);

namespace App\Tests\Research\Unit\Application;

use App\Research\Application\DataRequest\FieldChange;
use App\Research\Application\DataRequest\VersionComparison;
use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestVersion;
use App\Research\Domain\ValueObject\DataRequestContent;
use App\User\Domain\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Field-by-field comparison of two versions (ADR 045).
 */
final class VersionComparisonTest extends TestCase
{
    public function testOnlyChangedFieldsAreListedInFormOrder(): void
    {
        [$first, $second] = $this->versions(
            ['variables' => "Month\nPZC", 'periodTo' => '2024-12-31', 'singleCases' => false, 'ethics' => 'Not needed'],
            ['variables' => "Month\nPZC\nAge group", 'periodTo' => '2025-06-30', 'singleCases' => true, 'ethics' => 'Vote 2025-03'],
        );

        $changes = VersionComparison::compare($first, $second, true);

        self::assertSame(['periodTo', 'variables', 'singleCases', 'ethics'], array_map(static fn (FieldChange $change): string => $change->field, $changes));
        self::assertSame(['2024-12-31', '2025-06-30'], [$changes[0]->before, $changes[0]->after]);
        self::assertSame(FieldChange::DATE, $changes[0]->kind);
        self::assertSame(['0', '1'], [$changes[2]->before, $changes[2]->after]);
        self::assertSame([
            ['type' => 'same', 'text' => 'Month'],
            ['type' => 'same', 'text' => 'PZC'],
            ['type' => 'added', 'text' => 'Age group'],
        ], $changes[1]->lines);
        self::assertSame([
            ['type' => 'removed', 'text' => 'Not needed'],
            ['type' => 'added', 'text' => 'Vote 2025-03'],
        ], $changes[3]->lines);
    }

    public function testInternalFieldsStayHiddenWithoutTheRight(): void
    {
        [$first, $second] = $this->versions(
            ['recipients' => 'Team A', 'ethics' => 'Not needed', 'granularity' => 'Month'],
            ['recipients' => 'Team B', 'ethics' => 'Vote 2025-03', 'granularity' => 'Quarter'],
        );

        $changes = VersionComparison::compare($first, $second, false);

        self::assertSame(['granularity'], array_map(static fn (FieldChange $change): string => $change->field, $changes));
    }

    public function testIdenticalVersionsHaveNoChanges(): void
    {
        [$first, $second] = $this->versions([], []);

        self::assertSame([], VersionComparison::compare($first, $second, true));
    }

    /**
     * @param array<string, string|bool> $first
     * @param array<string, string|bool> $second
     *
     * @return array{DataRequestVersion, DataRequestVersion}
     */
    private function versions(array $first, array $second): array
    {
        $request = new DataRequest(new User(), $this->content($first), new \DateTimeImmutable('2026-12-01 09:00'));
        $request->recordSubmission(new \DateTimeImmutable('2026-12-01 10:00'));
        $request->addVersion($this->content($second), null, new \DateTimeImmutable('2026-12-02 09:00'));
        [$one, $two] = $request->getVersions();

        return [$one, $two];
    }

    /**
     * @param array<string, string|bool> $values
     */
    private function content(array $values): DataRequestContent
    {
        $text = static fn (string $field, string $default): string => \is_string($values[$field] ?? null) ? $values[$field] : $default;

        return new DataRequestContent(
            title: 'Title',
            summary: 'Summary',
            institution: 'University',
            enrichmentPlanned: false,
            purpose: 'Purpose',
            periodFrom: new \DateTimeImmutable('2024-01-01'),
            periodTo: new \DateTimeImmutable($text('periodTo', '2024-12-31')),
            population: 'All allocations',
            variables: $text('variables', 'Month'),
            granularity: $text('granularity', 'Month'),
            recipients: $text('recipients', 'Team'),
            enrichmentDetails: null,
            singleCases: true === ($values['singleCases'] ?? false),
            description: 'Description',
            dataNeeds: 'Data needs',
            responsiblePersons: 'Dr. A',
            conflictsOfInterest: 'none',
            ethics: $text('ethics', 'Not needed'),
        );
    }
}
