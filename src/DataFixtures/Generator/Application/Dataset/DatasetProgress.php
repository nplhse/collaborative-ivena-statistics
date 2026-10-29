<?php

declare(strict_types=1);

namespace App\DataFixtures\Generator\Application\Dataset;

use App\DataFixtures\Generator\Application\GeneratedMonth;

/**
 * Live progress of DatasetGenerator::generate() for a console or other caller. The run records the same
 * progress (GenerationRunTracker), so a wizard can poll the run instead.
 */
interface DatasetProgress
{
    public function hospitalStarted(DatasetHospitalPlan $hospital): void;

    public function monthGenerated(DatasetHospitalPlan $hospital, string $month, GeneratedMonth $generated): void;

    public function hospitalFinished(DatasetHospitalPlan $hospital): void;

    public function hospitalFailed(DatasetHospitalPlan $hospital, string $message): void;
}
