<?php

declare(strict_types=1);

namespace App\Tests\Allocation\Integration\Application\Explore;

use App\Allocation\Application\DTO\DataCatalog\CatalogCode;
use App\Allocation\Application\DTO\DataCatalog\CatalogObject;
use App\Allocation\Application\DTO\DataCatalog\CatalogVariable;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogMetadataResolver;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogRegistry;
use App\Allocation\Application\Explore\DataCatalog\DataCatalogType;
use App\Allocation\Application\Explore\DataCatalog\DataObjectDefinition;
use App\Allocation\Domain\Enum\AllocationUrgency;
use App\Allocation\Domain\Enum\HospitalSize;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every documented variable of every registered contributor must point to a mapped field; the
 * hand-written expectations guard the derivation from the Doctrine mapping (#1415).
 */
final class DataCatalogMetadataTest extends KernelTestCase
{
    public function testEveryDocumentedVariableResolvesAgainstTheMapping(): void
    {
        $objects = $this->objects();

        self::assertNotEmpty($objects);
        foreach ($objects as $object) {
            self::assertGreaterThan(0, $object->variableCount(), $object->key);
        }
    }

    public function testAllocationVariablesMatchTheDoctrineMapping(): void
    {
        $allocation = $this->object('allocation');

        $urgency = $this->variable($allocation, 'allocation.urgency');
        self::assertSame(['allocation', 'urgency', DataCatalogType::CODE, false], [$urgency->table, $urgency->column, $urgency->type, $urgency->nullable]);
        self::assertSame(
            array_map(static fn (AllocationUrgency $case): string => (string) $case->value, AllocationUrgency::cases()),
            array_map(static fn (CatalogCode $code): string => $code->code, $urgency->codes),
        );
        self::assertSame('label.urgency.emergency', $urgency->codes[0]->labelKey);

        $age = $this->variable($allocation, 'allocation.age');
        self::assertSame([DataCatalogType::INTEGER, false, 1, 99], [$age->type, $age->nullable, $age->range?->min, $age->range?->max]);

        $airway = $this->variable($allocation, 'allocation.assessment_airway');
        self::assertSame(['assessment', 'airway', DataCatalogType::CODE, true], [$airway->table, $airway->column, $airway->type, $airway->nullable], 'Nullable through the optional assessment.');

        $occasion = $this->variable($allocation, 'allocation.occasion');
        self::assertSame(['occasion_id', DataCatalogType::REFERENCE, true, 'occasion'], [$occasion->column, $occasion->type, $occasion->nullable, $occasion->referenceTable]);

        $createdAt = $this->variable($allocation, 'allocation.created_at');
        self::assertSame(['created_at', DataCatalogType::DATETIME, false], [$createdAt->column, $createdAt->type, $createdAt->nullable]);
    }

    public function testMciCaseAndHospitalVariablesMatchTheDoctrineMapping(): void
    {
        $mciCase = $this->object('mci_case');

        $mciId = $this->variable($mciCase, 'mci_case.mci_id');
        self::assertSame(['mci_case', 'mci_id', DataCatalogType::TEXT, false], [$mciId->table, $mciId->column, $mciId->type, $mciId->nullable]);

        $age = $this->variable($mciCase, 'mci_case.age');
        self::assertSame([DataCatalogType::INTEGER, true, 1, 99], [$age->type, $age->nullable, $age->range?->min, $age->range?->max]);

        $hospitalRef = $this->variable($mciCase, 'mci_case.hospital');
        self::assertSame(['hospital_id', DataCatalogType::REFERENCE, false, 'hospital'], [$hospitalRef->column, $hospitalRef->type, $hospitalRef->nullable, $hospitalRef->referenceTable]);

        $hospital = $this->object('hospital');

        $size = $this->variable($hospital, 'hospital.size');
        self::assertSame(['hospital', 'size', DataCatalogType::CODE, false], [$size->table, $size->column, $size->type, $size->nullable]);
        self::assertSame(
            array_map(static fn (HospitalSize $case): string => $case->value, HospitalSize::cases()),
            array_map(static fn (CatalogCode $code): string => $code->code, $size->codes),
        );
        self::assertSame(['hospital.size.Small', 'allocation'], [$size->codes[0]->labelKey, $size->codes[0]->domain]);

        $postalCode = $this->variable($hospital, 'hospital.postal_code');
        self::assertSame(['hospital', 'address_postal_code', DataCatalogType::TEXT, false], [$postalCode->table, $postalCode->column, $postalCode->type, $postalCode->nullable]);

        $latitude = $this->variable($hospital, 'hospital.latitude');
        self::assertSame([DataCatalogType::DECIMAL, true], [$latitude->type, $latitude->nullable]);
    }

    public function testRegionVariablesMatchTheDoctrineMapping(): void
    {
        $dispatchArea = $this->object('dispatch_area');

        $name = $this->variable($dispatchArea, 'dispatch_area.name');
        self::assertSame(['dispatch_area', 'name', DataCatalogType::TEXT, false], [$name->table, $name->column, $name->type, $name->nullable]);

        $state = $this->variable($dispatchArea, 'dispatch_area.state');
        self::assertSame(['state_id', DataCatalogType::REFERENCE, false, 'state'], [$state->column, $state->type, $state->nullable, $state->referenceTable]);

        $stateName = $this->variable($this->object('state'), 'state.name');
        self::assertSame(['state', 'name', DataCatalogType::TEXT, false], [$stateName->table, $stateName->column, $stateName->type, $stateName->nullable]);
    }

    public function testReferenceObjectVariablesMatchTheDoctrineMapping(): void
    {
        foreach (['speciality', 'department', 'assignment', 'infection', 'occasion', 'secondary_transport'] as $key) {
            $name = $this->variable($this->object($key), $key.'.name');
            self::assertSame([$key, 'name', DataCatalogType::TEXT, false], [$name->table, $name->column, $name->type, $name->nullable], $key);
        }

        $indication = $this->object('indication_normalized');
        $code = $this->variable($indication, 'indication_normalized.code');
        self::assertSame(['indication_normalized', 'code', DataCatalogType::INTEGER, false], [$code->table, $code->column, $code->type, $code->nullable]);

        $category = $this->variable($this->object('indication_group'), 'indication_group.category');
        self::assertSame(['indication_group', 'category', DataCatalogType::TEXT, true], [$category->table, $category->column, $category->type, $category->nullable]);
    }

    public function testRecordGroupListsTheIdAndTheStoredTimestamps(): void
    {
        foreach (['allocation', 'mci_case'] as $key) {
            $object = $this->object($key);
            $id = $this->variable($object, $key.'.id');
            self::assertSame(['record', $key, 'id', DataCatalogType::INTEGER, false], [$object->groups[array_key_last($object->groups)]->key, $id->table, $id->column, $id->type, $id->nullable], $key);
            self::assertNull($object->variable($key.'.updated_at'), $key.': no record timestamp');
        }

        $hospital = $this->object('hospital');
        $createdAt = $this->variable($hospital, 'hospital.created_at');
        self::assertSame(['hospital', 'created_at', DataCatalogType::DATETIME, false], [$createdAt->table, $createdAt->column, $createdAt->type, $createdAt->nullable]);
        $updatedAt = $this->variable($hospital, 'hospital.updated_at');
        self::assertSame(['updated_at', DataCatalogType::DATETIME, true], [$updatedAt->column, $updatedAt->type, $updatedAt->nullable]);

        $occasionId = $this->variable($this->object('occasion'), 'occasion.id');
        self::assertSame(['occasion', 'id', DataCatalogType::INTEGER, false], [$occasionId->table, $occasionId->column, $occasionId->type, $occasionId->nullable]);

        $encounter = $this->object('encounter');
        $encounterId = $this->variable($encounter, 'encounter.id');
        self::assertSame(['encounter', 'id', DataCatalogType::INTEGER, false], [$encounterId->table, $encounterId->column, $encounterId->type, $encounterId->nullable]);
        self::assertSame(DataCatalogType::DATETIME, $this->variable($encounter, 'encounter.created_at')->type);
    }

    public function testEncounterVariablesMatchTheDoctrineMapping(): void
    {
        $encounter = $this->object('encounter');
        self::assertSame('encounter', $encounter->domain);

        $sex = $this->variable($encounter, 'encounter.sex');
        self::assertSame(['encounter', 'sex', DataCatalogType::CODE, false], [$sex->table, $sex->column, $sex->type, $sex->nullable]);
        self::assertSame(['M', 'F', 'X'], array_map(static fn (CatalogCode $code): string => $code->code, $sex->codes));
        self::assertSame('encounter.review.value.sex_M', $sex->codes[0]->labelKey);

        $age = $this->variable($encounter, 'encounter.age');
        self::assertSame([DataCatalogType::INTEGER, false, 1, 99], [$age->type, $age->nullable, $age->range?->min, $age->range?->max]);

        $allocation = $this->variable($encounter, 'encounter.allocation');
        self::assertSame(['allocation_id', DataCatalogType::REFERENCE, true, 'allocation'], [$allocation->column, $allocation->type, $allocation->nullable, $allocation->referenceTable]);
    }

    public function testTranslationsExistInEnglishAndGerman(): void
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        foreach (['en', 'de'] as $locale) {
            $catalogue = $translator->getCatalogue($locale);
            foreach ($this->objects() as $object) {
                $keys = [$object->titleKey(), $object->descriptionKey()];
                foreach ($object->groups as $group) {
                    $keys[] = $group->labelKey();
                    foreach ($group->variables as $variable) {
                        $keys[] = $variable->labelKey();
                        $keys[] = $variable->descriptionKey();
                        $keys[] = $variable->type->labelKey();
                        if (null !== $variable->range?->unitKey) {
                            $keys[] = $variable->range->unitKey;
                        }

                        foreach ($variable->codes as $code) {
                            if (null !== $code->labelKey && null !== $code->domain) {
                                self::assertTrue($catalogue->has($code->labelKey, $code->domain), sprintf('%s: %s (%s)', $locale, $code->labelKey, $code->domain));
                            }
                        }
                    }
                }

                foreach ($keys as $key) {
                    $domain = str_starts_with($key, 'data_catalog.type.') ? 'allocation' : $object->domain;
                    self::assertTrue($catalogue->has($key, $domain), sprintf('%s: %s (%s)', $locale, $key, $domain));
                }
            }
        }
    }

    /**
     * @return list<CatalogObject>
     */
    private function objects(): array
    {
        $resolver = self::getContainer()->get(DataCatalogMetadataResolver::class);

        return array_map(
            static fn (DataObjectDefinition $definition): CatalogObject => $resolver->resolve($definition),
            self::getContainer()->get(DataCatalogRegistry::class)->definitions(),
        );
    }

    private function object(string $key): CatalogObject
    {
        foreach ($this->objects() as $object) {
            if ($object->key === $key) {
                return $object;
            }
        }

        self::fail('Missing data catalog object '.$key);
    }

    private function variable(CatalogObject $object, string $key): CatalogVariable
    {
        $variable = $object->variable($key);
        self::assertInstanceOf(CatalogVariable::class, $variable, $key);

        return $variable;
    }
}
