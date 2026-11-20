<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Explore;

use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\Explore\DataCatalog\CodeLabels;
use App\Allocation\Application\Explore\DataCatalog\DataCatalog;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogContributorInterface;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogMetadataResolver;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogRegistry;
use App\Allocation\Application\Explore\DataCatalog\DataObjectDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableGroupDefinition;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Edge cases of the mapping-based resolution and of visibility (#1415), with small test objects.
 */
final class DataCatalogResolutionTest extends KernelTestCase
{
    public function testPrefixedCodeLabelsUseThePrefixAndTheCode(): void
    {
        $object = $this->resolver()->resolve($this->hospital(CodeLabels::prefixed('hospital.tier.', 'messages')));
        $tier = $object->variable('test_hospital.tier');

        self::assertNotNull($tier);
        self::assertTrue($tier->nullable);
        self::assertSame(['hospital.tier.Basic', 'messages'], [$tier->codes[0]->labelKey, $tier->codes[0]->domain]);
        self::assertNull($object->variable('test_hospital.missing'));
    }

    public function testPrefixedCodeLabelsAppendTheSuffix(): void
    {
        $object = $this->resolver()->resolve($this->hospital(CodeLabels::prefixed('hospital.tier.', 'messages', '.name')));

        self::assertSame('hospital.tier.Basic.name', $object->variable('test_hospital.tier')?->codes[0]->labelKey);
    }

    public function testEnumLabelsNeedALabelMethod(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('HospitalTier has no label() method.');

        $this->resolver()->resolve($this->hospital(CodeLabels::fromEnumLabel('messages')));
    }

    public function testUnknownPropertyIsRejected(): void
    {
        $definition = new DataObjectDefinition('broken', Allocation::class, 'allocation', 'tabler:database', [
            new VariableGroupDefinition('time', [new VariableDefinition('broken.renamed', 'renamedField')]),
        ]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Data catalog variable "broken.renamed": "renamedField" is no mapped field or owning to-one association');

        $this->resolver()->resolve($definition);
    }

    public function testHiddenObjectsAreLeftOutAndReferencesToThemStayUnlinked(): void
    {
        $catalog = $this->catalog(granted: false);

        self::assertSame(['test_allocation'], array_map(static fn (CatalogObject $object): string => $object->key, $catalog->visibleObjects()));
        self::assertNull($catalog->visibleObject('test_hospital'));
        self::assertNull($catalog->visibleObject('test_allocation')?->variable('test_allocation.hospital')?->referenceObjectKey);
    }

    public function testReferencesLinkToVisibleObjects(): void
    {
        $hospital = $this->catalog(granted: true)->visibleObject('test_allocation')?->variable('test_allocation.hospital');

        self::assertNotNull($hospital);
        self::assertSame(['test_hospital', 'shared', 'hospital'], [$hospital->referenceObjectKey, $hospital->referenceObjectDomain, $hospital->referenceTable]);
    }

    private function resolver(): DataCatalogMetadataResolver
    {
        return self::getContainer()->get(DataCatalogMetadataResolver::class);
    }

    private function hospital(?CodeLabels $labels): DataObjectDefinition
    {
        return new DataObjectDefinition('test_hospital', Hospital::class, 'shared', 'tabler:building-hospital', [
            new VariableGroupDefinition('structure', [new VariableDefinition('test_hospital.tier', 'tier', codeLabels: $labels)]),
        ], grantedAttribute: 'ROLE_TEST_HOSPITALS');
    }

    private function catalog(bool $granted): DataCatalog
    {
        $allocation = new DataObjectDefinition('test_allocation', Allocation::class, 'allocation', 'tabler:ambulance', [
            new VariableGroupDefinition('destination', [new VariableDefinition('test_allocation.hospital', 'hospital')]),
        ]);
        $contributor = new readonly class([$allocation, $this->hospital(null)]) implements DataCatalogContributorInterface {
            /**
             * @param list<DataObjectDefinition> $objects
             */
            public function __construct(private array $objects)
            {
            }

            #[\Override]
            public function dataObjects(): array
            {
                return $this->objects;
            }
        };
        $authorization = new readonly class($granted) implements AuthorizationCheckerInterface {
            public function __construct(private bool $granted)
            {
            }

            #[\Override]
            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                return $this->granted;
            }
        };

        return new DataCatalog(new DataCatalogRegistry([$contributor]), $this->resolver(), $authorization);
    }
}
