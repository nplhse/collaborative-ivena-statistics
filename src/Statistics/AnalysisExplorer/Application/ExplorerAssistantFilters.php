<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Application;

final readonly class ExplorerAssistantFilters
{
    public function __construct(
        public ?int $departmentId = null,
        public ?int $specialityId = null,
        public ?int $urgency = null,
        public ?int $transportType = null,
        public ?int $gender = null,
        public ?string $ageGroup = null,
        public ?bool $resus = null,
        public ?bool $cpr = null,
        public ?bool $ventilation = null,
        public ?int $assignmentId = null,
        public ?int $indicationId = null,
        public ?int $secondaryIndicationId = null,
        public ?int $indicationGroupId = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * @return array<string, string>
     */
    public function parameters(): array
    {
        $params = [];
        $this->putInt($params, 'department', $this->departmentId);
        $this->putInt($params, 'speciality', $this->specialityId);
        $this->putInt($params, 'urgency', $this->urgency);
        $this->putInt($params, 'transport_type', $this->transportType);
        $this->putInt($params, 'gender', $this->gender);
        if (null !== $this->ageGroup && '' !== $this->ageGroup) {
            $params['age_group'] = $this->ageGroup;
        }
        $this->putBool($params, 'resus', $this->resus);
        $this->putBool($params, 'cpr', $this->cpr);
        $this->putBool($params, 'ventilation', $this->ventilation);
        $this->putInt($params, 'assignment', $this->assignmentId);
        $this->putInt($params, 'indication', $this->indicationId);
        $this->putInt($params, 'secondary_indication', $this->secondaryIndicationId);
        $this->putInt($params, 'indication_group', $this->indicationGroupId);

        return $params;
    }

    /**
     * @param array<string, string> $params
     */
    private function putInt(array &$params, string $key, ?int $value): void
    {
        if (null === $value) {
            return;
        }

        $params[$key] = (string) $value;
    }

    /**
     * @param array<string, string> $params
     */
    private function putBool(array &$params, string $key, ?bool $value): void
    {
        if (null === $value) {
            return;
        }

        $params[$key] = $value ? '1' : '0';
    }
}
