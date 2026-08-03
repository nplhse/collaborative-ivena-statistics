<?php

declare(strict_types=1);

namespace App\Statistics\AnalysisExplorer\Domain\Enum;

/**
 * Controlled topical vocabulary for library findability (multi-assignable).
 */
enum AnalysisTopic: string
{
    case Allocations = 'allocations';
    case Indications = 'indications';
    case IndicationGroups = 'indication_groups';
    case Occasions = 'occasions';
    case Infections = 'infections';
    case Assignments = 'assignments';
    case Departments = 'departments';
    case Specialties = 'specialties';
    case Hospitals = 'hospitals';
    case Urgency = 'urgency';
    case Age = 'age';
    case Gender = 'gender';
    case Transport = 'transport';
    case TransportTime = 'transport_time';
    case PhysicianAttendance = 'physician_attendance';
    case ResusRoom = 'resus_room';
    case Ventilation = 'ventilation';
    case Resuscitation = 'resuscitation';
    case ClinicalShock = 'clinical_shock';
    case TimePatterns = 'time_patterns';
    case DataQuality = 'data_quality';
}
