<?php

declare(strict_types=1);

namespace Cois\Plugin\EdVisit\UI\DataPrep;

use App\Encounter\Contract\DataPrep\DataPrepCatalog;
use App\Encounter\Contract\DataPrep\DataPrepContributionInterface;
use App\Encounter\Contract\DataPrep\DataPrepLocalMap;
use Cois\Plugin\EdVisit\Application\Catalog\MtsCatalog;
use Cois\Plugin\EdVisit\Application\Dimension\EdVisitDimension;
use Cois\Plugin\EdVisit\Domain\Enum\CareLevel;
use Cois\Plugin\EdVisit\Domain\Enum\Disposition;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The `ed_visit` part of the data preparation tool (#1301): schema file, the ORBIS MTS profile, the schema module
 * (vitals, dispositions, care levels, departments, MTS catalog format), the bundled MTS catalog with its public
 * endpoint and the local map of departments, stations and dispositions.
 *
 * The files still live in the core until step 4h of #1300 moves them next to this class; until then their paths
 * are built from the project directory, which is what a deployed release has as well.
 *
 * @psalm-suppress UnusedClass Tagged via DataPrepContributionInterface.
 */
final readonly class EdVisitDataPrepContribution implements DataPrepContributionInterface
{
    private const string ASSETS = '/src/Encounter/UI/DataPrep';

    /** @psalm-suppress PossiblyUnusedMethod Autowired. */
    public function __construct(
        private MtsCatalog $mtsCatalog,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    #[\Override]
    public function schema(): string
    {
        return EdVisitDimension::SCHEMA;
    }

    #[\Override]
    public function schemaFile(): string
    {
        return $this->asset('/schema/ed_visit.v1.json');
    }

    #[\Override]
    public function profileFiles(): array
    {
        return [$this->asset('/profiles/orbis-mts.json')];
    }

    #[\Override]
    public function scriptFile(): string
    {
        return $this->asset('/js/schemas/ed-visit.js');
    }

    #[\Override]
    public function catalogs(): array
    {
        return [new DataPrepCatalog(MtsCatalog::ID, $this->mtsCatalog->bundledToolCatalog(), 'app_encounter_data_prep_mts_catalog')];
    }

    #[\Override]
    public function localMap(): DataPrepLocalMap
    {
        return new DataPrepLocalMap(
            $this->asset('/local-map/template.json'),
            $this->asset('/local-map/example.json'),
            [
                'care_levels' => CareLevel::cases(),
                'dispositions' => Disposition::cases(),
                'care_level_dispositions' => [Disposition::Admitted, Disposition::TransferredExternal],
            ],
        );
    }

    private function asset(string $path): string
    {
        return $this->projectDir.self::ASSETS.$path;
    }
}
