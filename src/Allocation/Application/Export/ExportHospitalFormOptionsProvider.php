<?php

declare(strict_types=1);

namespace App\Allocation\Application\Export;

use App\Allocation\Infrastructure\Repository\HospitalRepository;
use App\Statistics\Application\StatisticsHospitalScopeLabelResolver;
use App\User\Domain\Entity\User;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class ExportHospitalFormOptionsProvider
{
    public function __construct(
        private HospitalRepository $hospitalRepository,
        private StatisticsHospitalScopeLabelResolver $hospitalScopeLabelResolver,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function formOptionsFor(User $user, ?string $locale = null): array
    {
        $options = $this->optionsFor($user, $locale);
        unset($options['default_hospital_ids']);

        return $options;
    }

    /**
     * @return list<int>
     */
    public function defaultHospitalIdsFor(User $user): array
    {
        /** @var list<int> $ids */
        $ids = $this->optionsFor($user)['default_hospital_ids'];

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    public function optionsFor(User $user, ?string $locale = null): array
    {
        $hospitals = $this->hospitalRepository->findExportableHospitalSummaries($user);
        $choices = [];
        foreach ($hospitals as $row) {
            $choices[$row['name']] = $row['id'];
        }

        $hospitalIds = array_values($choices);

        return [
            'hospital_choices' => $choices,
            'default_hospital_ids' => $hospitalIds,
            'hospitals_section_label' => $this->hospitalScopeLabelResolver->groupLabel($user, $locale),
            'hospitals_help' => \count($hospitals) > 1
                ? $this->translator->trans('help.export.hospitals', [], null, $locale)
                : '',
        ];
    }
}
