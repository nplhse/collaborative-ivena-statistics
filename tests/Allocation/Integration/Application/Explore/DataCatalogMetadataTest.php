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
