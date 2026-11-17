<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Tests\Fixtures\Plugin\InpatientStay\InpatientStayTestBundle;
use App\Tests\Support\Encounter\Probe\ProbeDimension;
use App\Tests\Support\Encounter\Probe\ProbeSchemaHandler;
use Cois\Plugin\EdVisit\EdVisitBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The application without any encounter schema: neither the ED visit plugin nor the test bundle `inpatient_stay`,
 * and without the test-only schema `probe` that config/services/encounter_probe_test.yaml registers.
 */
final class KernelWithoutEncounterSchemas extends KernelWithoutBundles
{
    #[\Override]
    protected function withoutBundles(): array
    {
        return [EdVisitBundle::class, InpatientStayTestBundle::class];
    }

    #[\Override]
    protected function cacheSuffix(): string
    {
        return 'without_encounter_schemas';
    }

    #[\Override]
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        // After autoconfiguration has tagged the services, before the tagged iterators are resolved.
        $container->addCompilerPass(new class implements CompilerPassInterface {
            #[\Override]
            public function process(ContainerBuilder $container): void
            {
                $container->removeDefinition(ProbeDimension::class);
                $container->removeDefinition(ProbeSchemaHandler::class);
            }
        }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 50);
    }
}
