<?php

declare(strict_types=1);

namespace App\Import\Application\Analysis;

use App\Import\Infrastructure\Mapping\DispatchAreaSourceResolver;

/**
 * @phpstan-type NormalizedReject array{field: string, rejected_value: string, reason: string}
 */
final readonly class RejectMessageNormalizer
{
    private const string UNKNOWN_FIELD = '(unknown)';

    private const string EMPTY_VALUE = '(empty)';

    /** @var array<string, string> */
    private const array DTO_FIELD_TO_ROW_KEY = [
        'hospital' => 'krankenhaus_kurzname',
        'createdAt' => 'datum_erstellungsdatum',
        'arrivalAt' => 'datum_eintreffzeit',
        'gender' => 'geschlecht',
        'age' => 'alter',
        'requiresResus' => 'schockraum',
        'requiresCathlab' => 'herzkatheter',
        'isCPR' => 'reanimation',
        'isVentilated' => 'beatmet',
        'isShock' => 'schock',
        'isPregnant' => 'schwanger',
        'isWorkAccident' => 'arbeits_wege_schulunfall',
        'isWithPhysician' => 'arztbegleitet',
        'transportType' => 'transportmittel',
        'urgency' => 'pzc',
        'speciality' => 'fachgebiet',
        'department' => 'fachbereich',
        'departmentWasClosed' => 'fachbereich_war_abgemeldet',
        'assignment' => 'grund',
        'occasion' => 'anlass',
        'secondaryTransport' => 'sekundaeranlass',
        'infection' => 'ansteckungsfaehig',
        'indicationCode' => 'pzc',
        'indication' => 'pzc_und_text',
        'secondaryIndicationCode' => 'neben_pzc',
        'secondaryIndication' => 'neben_pzc_text',
        'caseId' => 'enr',
        'notes' => 'freitext',
    ];

    public function __construct(
        private DispatchAreaSourceResolver $dispatchAreaSourceResolver = new DispatchAreaSourceResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return NormalizedReject
     */
    public function normalize(string $message, array $row): array
    {
        $reason = trim($message);
        $field = $this->extractField($reason);
        $rejectedValue = $this->extractRejectedValue($reason, $field, $row);

        return [
            'field' => $field,
            'rejected_value' => $rejectedValue,
            'reason' => $reason,
        ];
    }

    private function extractField(string $message): string
    {
        if (preg_match('/\bfield=([A-Za-z0-9_\.]+)/', $message, $matches)) {
            return $matches[1];
        }

        if (preg_match('/\bfor "([A-Za-z0-9_\.]+)"/', $message, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^([A-Za-z0-9_\.\[\]]+):\s/', $message, $matches)) {
            return trim($matches[1], '[]');
        }

        return self::UNKNOWN_FIELD;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function extractRejectedValue(string $message, string $field, array $row): string
    {
        if (preg_match('/\bvalue="([^"]*)"/', $message, $matches)) {
            return $this->normalizeScalarValue($matches[1], $message);
        }

        if (self::UNKNOWN_FIELD !== $field) {
            $rowKey = $this->resolveRowKey($field, $row);
            if (\array_key_exists($rowKey, $row)) {
                return $this->normalizeScalarValue($row[$rowKey], $message);
            }
        }

        if ($this->isEmptyValueMessage($message)) {
            return self::EMPTY_VALUE;
        }

        return '';
    }

    private function normalizeScalarValue(mixed $value, string $message): string
    {
        if (null === $value) {
            return self::EMPTY_VALUE;
        }

        $stringValue = trim((string) $value);

        if ('' === $stringValue && $this->isEmptyValueMessage($message)) {
            return self::EMPTY_VALUE;
        }

        return $stringValue;
    }

    private function isEmptyValueMessage(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'not be blank')
            || str_contains($lower, 'should not be null')
            || str_contains($lower, 'cannot be empty')
            || str_contains($lower, 'is required');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveRowKey(string $field, array $row): string
    {
        if ('dispatchArea' === $field) {
            return $this->dispatchAreaSourceResolver->resolveRowKey($row);
        }

        return self::DTO_FIELD_TO_ROW_KEY[$field] ?? $field;
    }
}
