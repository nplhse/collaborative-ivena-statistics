<?php

declare(strict_types=1);

namespace App\Allocation\UI\Form\Model;

final class OwnHospitalAllocationsExportFormData
{
    public ?\DateTimeInterface $dateFrom = null;

    public ?\DateTimeInterface $dateTo = null;

    public ?\DateTimeInterface $timeFrom = null;

    public ?\DateTimeInterface $timeTo = null;

    /** @var list<int> */
    public array $hospitals = [];

    public ?string $urgency = null;

    public ?int $indication = null;

    public ?int $secondaryTransport = null;

    public ?int $department = null;

    public ?int $speciality = null;

    public ?int $assignment = null;

    public ?int $occasion = null;

    public bool $departmentWasClosed = false;

    public ?string $transportType = null;

    public bool $requiresResus = false;

    public bool $requiresCathlab = false;

    public bool $isVentilated = false;

    public bool $isShock = false;

    public bool $isCPR = false;

    public bool $isPregnant = false;

    public bool $isWorkAccident = false;

    public bool $isInfectious = false;

    public ?int $infection = null;

    public bool $includeIndicationRaw = false;
}
