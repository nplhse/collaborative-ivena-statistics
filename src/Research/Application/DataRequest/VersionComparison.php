<?php

declare(strict_types=1);

namespace App\Research\Application\DataRequest;

use App\Research\Domain\Entity\DataRequest;
use App\Research\Domain\Entity\DataRequestVersion;
use App\User\Domain\Entity\User;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Compares two versions of a data extract request field by field (ADR 045). Readers without access to the internal
 * details compare only the fields they can see: the visible fields and the export scope without recipients and
 * enrichment details.
 */
final class VersionComparison
{
    /** Field => [translation key suffix, kind, internal], in the order of the request form. */
    private const array FIELDS = [
        'title' => ['title', FieldChange::TEXT, false],
        'summary' => ['summary', FieldChange::TEXT, false],
        'institution' => ['institution', FieldChange::TEXT, false],
        'enrichmentPlanned' => ['enrichment_planned', FieldChange::FLAG, false],
        'purpose' => ['purpose', FieldChange::TEXT, false],
        'periodFrom' => ['period_from', FieldChange::DATE, false],
        'periodTo' => ['period_to', FieldChange::DATE, false],
        'population' => ['population', FieldChange::TEXT, false],
        'variables' => ['variables', FieldChange::TEXT, false],
        'granularity' => ['granularity', FieldChange::TEXT, false],
        'recipients' => ['recipients', FieldChange::TEXT, true],
        'enrichmentDetails' => ['enrichment_details', FieldChange::TEXT, true],
        'singleCases' => ['single_cases', FieldChange::FLAG, false],
        'description' => ['description', FieldChange::TEXT, true],
        'dataNeeds' => ['data_needs', FieldChange::TEXT, true],
        'responsiblePersons' => ['responsible_persons', FieldChange::TEXT, true],
        'conflictsOfInterest' => ['conflicts_of_interest', FieldChange::TEXT, true],
        'ethics' => ['ethics', FieldChange::TEXT, true],
        'plannedEnd' => ['planned_end', FieldChange::DATE, true],
        'plannedDeletion' => ['planned_deletion', FieldChange::DATE, true],
        'plannedPublication' => ['planned_publication', FieldChange::TEXT, true],
    ];

    /**
     * What a reader compares (ADR 045): the versions they may see (the applicant also one not submitted yet), the chosen
     * pair, by default the latest with the one before, and the changes between them; null when there is no pair.
     *
     * @return array{versions: list<DataRequestVersion>, from: ?DataRequestVersion, to: ?DataRequestVersion, changes: list<FieldChange>|null}
     */
    public static function forReader(DataRequest $request, User $reader, int $from, int $to, bool $withInternal): array
    {
        $versions = array_values(array_filter(
            $request->getVersions(),
            static fn (DataRequestVersion $version): bool => $version->isSubmitted() || $request->isRequestedBy($reader),
        ));
        $byNumber = [];
        foreach ($versions as $version) {
            $byNumber[$version->getNumber()] = $version;
        }

        $toVersion = $byNumber[$to] ?? ([] !== $versions ? $versions[\count($versions) - 1] : null);
        $fromVersion = $byNumber[$from] ?? ($toVersion instanceof DataRequestVersion ? ($byNumber[$toVersion->getNumber() - 1] ?? null) : null);
        $changes = $fromVersion instanceof DataRequestVersion && $toVersion instanceof DataRequestVersion && $fromVersion !== $toVersion
            ? self::compare($fromVersion, $toVersion, $withInternal)
            : null;

        return ['versions' => $versions, 'from' => $fromVersion, 'to' => $toVersion, 'changes' => $changes];
    }

    /**
     * @return list<FieldChange> only the fields that differ
     */
    public static function compare(DataRequestVersion $from, DataRequestVersion $to, bool $withInternal): array
    {
        $before = get_object_vars($from->toContent());
        $after = get_object_vars($to->toContent());
        $differ = new Differ(new UnifiedDiffOutputBuilder());

        $changes = [];
        foreach (self::FIELDS as $field => [$key, $kind, $internal]) {
            if ($internal && !$withInternal) {
                continue;
            }

            $old = self::text($before[$field] ?? null);
            $new = self::text($after[$field] ?? null);
            if ($old === $new) {
                continue;
            }

            $lines = FieldChange::TEXT === $kind ? self::lines($differ, (string) $old, (string) $new) : [];
            $changes[] = new FieldChange($field, 'data_request.field.'.$key, $kind, $old, $new, $lines);
        }

        return $changes;
    }

    /**
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private static function lines(Differ $differ, string $old, string $new): array
    {
        $lines = [];
        foreach ($differ->diffToArray('' === $old ? [] : explode("\n", $old), '' === $new ? [] : explode("\n", $new)) as [$text, $type]) {
            $type = match ($type) {
                Differ::ADDED => 'added',
                Differ::REMOVED => 'removed',
                Differ::OLD => 'same',
                default => null,
            };
            if (null !== $type) {
                $lines[] = ['type' => $type, 'text' => rtrim((string) $text, "\r\n")];
            }
        }

        return $lines;
    }

    private static function text(mixed $value): ?string
    {
        return match (true) {
            null === $value => null,
            $value instanceof \DateTimeImmutable => $value->format('Y-m-d'),
            \is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }
}
