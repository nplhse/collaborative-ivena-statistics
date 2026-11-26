<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Explore;

use App\Allocation\Application\DTO\DataCatalog\CatalogCode;
use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\Explore\DataCatalog\CodeLabels;
use App\Allocation\Application\Explore\DataCatalog\DataCatalog;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogContributorInterface;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogMetadataResolver;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogRegistry;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;
use App\Allocation\Application\Explore\DataCatalog\DataObjectDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableDefinition;
use App\Allocation\Application\Explore\DataCatalog\VariableGroupDefinition;
use App\Allocation\Domain\Entity\Allocation;
use App\Allocation\Domain\Entity\Hospital;
use App\Allocation\Domain\Enum\HospitalTier;
use App\Import\Domain\Entity\Import;
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

    public function testCodeSetTakesItsCodesFromTheNamedEnum(): void
    {
        $object = $this->resolver()->resolve($this->codeSet('dataCoverage', HospitalTier::class));
        $tiers = $object->variable('test_import.tiers');

        self::assertNotNull($tiers);
        self::assertSame([DataCatalogType::CODE_SET, 'data_coverage', true], [$tiers->type, $tiers->column, $tiers->nullable]);
        self::assertSame(['Basic', 'Extended', 'Full'], array_map(static fn (CatalogCode $code): string => $code->code, $tiers->codes));
        self::assertSame('hospital.tier.Full', $tiers->codes[2]->labelKey);
    }

    public function testCodeSetWithAnUnknownEnumIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Data catalog variable "test_import.tiers": code set enum "App\\Missing\\TierCode" is no backed enum.');

        $this->resolver()->resolve($this->codeSet('dataCoverage', 'App\\Missing\\TierCode'));
    }

    public function testCodeSetWithAClassThatIsNoBackedEnumIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('code set enum "App\\Import\\Domain\\Entity\\Import" is no backed enum.');

        $this->resolver()->resolve($this->codeSet('dataCoverage', Import::class));
    }

    public function testCodeSetOnAColumnThatIsNotJsonIsRejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Data catalog variable "test_import.tiers": a code set needs a JSON column, "name" is mapped as string.');

        $this->resolver()->resolve($this->codeSet('name', HospitalTier::class));
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

    private function codeSet(string $property, string $enum): DataObjectDefinition
    {
        return new DataObjectDefinition('test_import', Import::class, 'import', 'tabler:file-import', [
            new VariableGroupDefinition('context', [new VariableDefinition('test_import.tiers', $property, codeLabels: CodeLabels::prefixed('hospital.tier.', 'messages'), codeSetEnum: $enum)]),
        ]);
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
