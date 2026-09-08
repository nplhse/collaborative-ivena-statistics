<?php

declare(strict_types=1);

namespace App\User\Application\Mail;

use App\User\Domain\Entity\User;

final readonly class WelcomeEmailNextStepsBuilder
{
    /**
     * @return list<WelcomeEmailNextStep>
     */
    public function buildForUser(User $user): array
    {
        $ownsHospitals = $user->getHospitals()->count() > 0;
        $steps = [];

        if ($ownsHospitals) {
            $steps[] = new WelcomeEmailNextStep(
                'email.welcome.next_steps.hospitals.owned.title',
                'email.welcome.next_steps.hospitals.owned.description',
                'app_hospitals_index',
            );
        } else {
            $steps[] = new WelcomeEmailNextStep(
                'email.welcome.next_steps.hospitals.all.title',
                'email.welcome.next_steps.hospitals.all.description',
                'app_explore_hospital_list',
            );
        }

        $steps[] = new WelcomeEmailNextStep(
            'email.welcome.next_steps.explore.title',
            'email.welcome.next_steps.explore.description',
            'app_explore_index',
        );

        $steps[] = new WelcomeEmailNextStep(
            'email.welcome.next_steps.analysis.title',
            'email.welcome.next_steps.analysis.description',
            'app_stats_dashboard',
        );

        if ($ownsHospitals) {
            $steps[] = new WelcomeEmailNextStep(
                'email.welcome.next_steps.import.title',
                'email.welcome.next_steps.import.description',
                'app_import_new',
            );
        }

        return $steps;
    }
}
