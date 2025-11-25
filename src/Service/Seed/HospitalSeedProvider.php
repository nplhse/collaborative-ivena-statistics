<?php

namespace App\Service\Seed;

use App\Entity\Address;
use App\Entity\Hospital;
use App\Enum\HospitalLocation;
use App\Enum\HospitalSize;
use App\Enum\HospitalTier;
use App\Service\Seed\Areas\AreaCache;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<array{
 *     state: string,
 *     area: string,
 *     name: string,
 *     code?: string,
 *     tier?: string|null,
 *     size: string,
 *     beds: int,
 *     location: string,
 *     address: array{
 *          street: string,
 *          city: string,
 *          state: string,
 *          postalCode: string,
 *          country: string
 *      },
 *     participating: bool
 * }>
 */
#[AsTaggedItem('app.seed_provider', priority: 100)]
final class HospitalSeedProvider implements SeedProviderInterface
{
    public function __construct(
        private AreaCache $areaCache,
    ) {
    }

    /**
     * @return iterable<object>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        $this->areaCache->warmUp();

        foreach ($this->provide() as $row) {
            if (!$this->areaCache->hasState($row['state'])) {
                throw new \RuntimeException("Unknown state '{$row['state']}' for hospital '{$row['name']}'");
            }

            if (!$this->areaCache->hasArea($row['state'], $row['area'])) {
                throw new \RuntimeException("Unknown area '{$row['area']}' in state '{$row['state']}' for hospital '{$row['name']}'");
            }

            $stateRef = $this->areaCache->getStateRef($row['state']);
            $areaRef = $this->areaCache->getAreaRef($row['state'], $row['area']);

            $address = new Address();
            $address->setStreet('123 Fake Street');
            $address->setCity('Fake City');
            $address->setState('State');
            $address->setPostalCode('12345');
            $address->setCountry('Deutschland');

            $hospital = new Hospital()
                ->setName($row['name'])
                ->setState($stateRef)
                ->setDispatchArea($areaRef)
                ->setTier(isset($row['tier']) ? HospitalTier::from($row['tier']) : null)
                ->setLocation(HospitalLocation::from($row['location']))
                ->setSize(HospitalSize::from($row['size']))
                ->setBeds($row['beds'])
                ->setAddress(
                    new Address()
                        ->setStreet($row['address']['street'])
                        ->setCity($row['address']['city'])
                        ->setPostalCode($row['address']['postalCode'])
                        ->setCountry($row['address']['country'])
                        ->setState($row['address']['state'])
                )
                ->setParticipating($row['participating'])
                ->setOwner(null)
                ->setCreatedBy($user);

            yield $hospital;
        }
    }

    /**
     * @implements SeedProviderInterface<array{
     *     state: string,
     *     area: string,
     *     name: string,
     *     code?: string,
     *     tier?: string|null,
     *     size: string,
     *     beds: int,
     *     location: string,
     *     address: array{
     *          street: string,
     *          city: string,
     *          state: string,
     *          postalCode: string,
     *          country: string
     *      },
     *     participating: bool
     * }>
     */
    #[\Override]
    public function provide(): iterable
    {
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Agaplesion Bethanien Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 204,
            'location' => 'Urban',
            'address' => [
                'street' => 'Im Prüfling 21-25',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60389',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Kassel',
            'name' => 'Agaplesion Diakonie Kliniken Kassel',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 316,
            'location' => 'Urban',
            'address' => [
                'street' => 'Herkulesstraße 34',
                'city' => 'Kassel',
                'state' => 'Hessen',
                'postalCode' => '34119',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Darmstadt',
            'name' => 'Agaplesion Elisabethenstift Evangelisches Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Large',
            'beds' => 419,
            'location' => 'Urban',
            'address' => [
                'street' => 'Landgraf-Georg-Straße 100',
                'city' => 'Darmstadt',
                'state' => 'Hessen',
                'postalCode' => '64287',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Gießen',
            'name' => 'Agaplesion Evangelisches Krankenhaus Mittelhessen',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 263,
            'location' => 'Urban',
            'address' => [
                'street' => 'Paul-Zipp-Straße 171',
                'city' => 'Gießen',
                'state' => 'Hessen',
                'postalCode' => '35398',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Agaplesion Markus Krankenhaus',
            'tier' => 'Extended',
            'size' => 'Large',
            'beds' => 635,
            'location' => 'Urban',
            'address' => [
                'street' => 'Wilhelm-Epstein-Straße 4',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60431',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Darmstadt',
            'name' => 'Alice-Hospital',
            'tier' => null,
            'size' => 'Medium',
            'beds' => 146,
            'location' => 'Urban',
            'address' => [
                'street' => 'Dieburger Straße 31',
                'city' => '64287',
                'state' => 'Hessen',
                'postalCode' => 'Darmstadt',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Offenbach',
            'name' => 'Asklepios Klinik Langen',
            'tier' => 'Extended',
            'size' => 'Large',
            'beds' => 431,
            'location' => 'Urban',
            'address' => [
                'street' => 'Röntgenstraße 20',
                'city' => 'Langen(Hessen)',
                'state' => 'Hessen',
                'postalCode' => '63225',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Gießen',
            'name' => 'Asklepios Klinik Lich',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 244,
            'location' => 'Rural',
            'address' => [
                'street' => 'Goethestraße 4',
                'city' => 'Lich',
                'state' => 'Hessen',
                'postalCode' => '35423',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Offenbach',
            'name' => 'Asklepios Klinik Seligenstadt',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 265,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Dudenhöfer Straße 9',
                'city' => 'Seligenstadt',
                'state' => 'Hessen',
                'postalCode' => '63500',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Wiesbaden',
            'name' => 'Asklepios Paulinen Klinik Wiesbaden',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 293,
            'location' => 'Urban',
            'address' => [
                'street' => 'Geisenheimer Straße 10',
                'city' => 'Wiesbaden',
                'state' => 'Hessen',
                'postalCode' => '65197',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Waldeck-Frankenberg',
            'name' => 'Asklepios Stadtklinik Bad Wildungen',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 175,
            'location' => 'Rural',
            'address' => [
                'street' => 'Brunnenallee 19',
                'city' => 'Bad Wildungen',
                'state' => 'Hessen',
                'postalCode' => '34537',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'BG Unfallklinik Frankfurt am Main',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 386,
            'location' => 'Urban',
            'address' => [
                'street' => 'Friedberger Landstraße 430',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60389',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Bürgerhospital Frankfurt',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 320,
            'location' => 'Urban',
            'address' => [
                'street' => 'Nibelungenallee 37-41',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60318',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Wetterau',
            'name' => 'Bürgerhospital Friedberg',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 267,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Ockstädter Straße 3-5',
                'city' => 'Friedberg(Hessen)',
                'state' => 'Hessen',
                'postalCode' => '61169',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Marburg-Biedenkopf',
            'name' => 'DGD Diakonie-Krankenhaus Wehrda',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 208,
            'location' => 'Urban',
            'address' => [
                'street' => 'Hebronberg 5',
                'city' => 'Marburg',
                'state' => 'Hessen',
                'postalCode' => '35041',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'DGD Krankenhaus Sachsenhausen',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 211,
            'location' => 'Urban',
            'address' => [
                'street' => 'Schulstraße 31',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60594',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Marburg-Biedenkopf',
            'name' => 'DRK Krankenhaus Biedenkopf',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 113,
            'location' => 'Rural',
            'address' => [
                'street' => 'Hainstraße 71-75',
                'city' => 'Biedenkopf',
                'state' => 'Hessen',
                'postalCode' => '35216',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Kassel',
            'name' => 'Elisabeth-Krankenhaus Kassel',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 198,
            'location' => 'Urban',
            'address' => [
                'street' => 'Weinbergstraße 7',
                'city' => 'Kassel',
                'state' => 'Hessen',
                'postalCode' => '34117',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Frankfurter Rotkreuz-Kliniken',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 300,
            'location' => 'Urban',
            'address' => [
                'street' => 'Königswarterstraße 8-16',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60316',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Groß-Gerau',
            'name' => 'GPR Klinikum Rüsselsheim',
            'tier' => 'Extended',
            'size' => 'Large',
            'beds' => 413,
            'location' => 'Urban',
            'address' => [
                'street' => 'August-Bebel-Str. 59',
                'city' => 'Rüsselsheim',
                'state' => 'Hessen',
                'postalCode' => '65428',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Bergstraße',
            'name' => 'Heilig-Geist Hospital',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 134,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Rodensteinstraße 94',
                'city' => 'Bensheim',
                'state' => 'Hessen',
                'postalCode' => '64625',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Wiesbaden',
            'name' => 'Helios Dr. Horst-Schmidt-Kliniken Wiesbaden',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 730,
            'location' => 'Urban',
            'address' => [
                'street' => 'Ludwig-Erhard-Straße 90',
                'city' => 'Wiesbaden',
                'state' => 'Hessen',
                'postalCode' => '65199',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Rheingau Taunus',
            'name' => 'Helios Klinik Idstein',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 83,
            'location' => 'Rural',
            'address' => [
                'street' => 'Robert-Koch-Straße 2',
                'city' => 'Idstein',
                'state' => 'Hessen',
                'postalCode' => '65510',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Kassel',
            'name' => 'Helios Kliniken Kassel - Standort Wehlheiden',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 219,
            'location' => 'Urban',
            'address' => [
                'street' => 'Hansteinstraße 29',
                'city' => '34121',
                'state' => 'Hessen',
                'postalCode' => 'Kassel',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Fulda',
            'name' => 'Helios St. Elisabeth Klinik Hünfeld',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 158,
            'location' => 'Rural',
            'address' => [
                'street' => 'Schillerstraße 22',
                'city' => 'Hünfeld',
                'state' => 'Hessen',
                'postalCode' => '36088',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Fulda',
            'name' => 'Herz-Jesu-Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 354,
            'location' => 'Urban',
            'address' => [
                'street' => 'Buttlarstraße 74',
                'city' => 'Fulda',
                'state' => 'Hessen',
                'postalCode' => '36039',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Hochtaunus',
            'name' => 'Hochtaunus-Kliniken - Bad Homburg',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 474,
            'location' => 'Urban',
            'address' => [
                'street' => 'Zeppelinstraße 20',
                'city' => 'Bad Homburg v d Höhe',
                'state' => 'Hessen',
                'postalCode' => '61352',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Hochtaunus',
            'name' => 'Hochtaunus-Kliniken - Usingen',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 100,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Weilburger Straße 48',
                'city' => 'Usingen',
                'state' => 'Hessen',
                'postalCode' => '61250',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Wetterau',
            'name' => 'Hochwaldkrankenhaus Bad Nauheim',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 241,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Chaumontplatz 1',
                'city' => 'Bad Nauheim',
                'state' => 'Hessen',
                'postalCode' => '61231',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Hospital zum Heiligen Geist',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 226,
            'location' => 'Urban',
            'address' => [
                'street' => 'Lange Straße 4-6',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60311',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Schwalm-Eder',
            'name' => 'Hospital zum Heiligen Geist',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 177,
            'location' => 'Rural',
            'address' => [
                'street' => 'Am Hospital 6',
                'city' => 'Fritzlar',
                'state' => 'Hessen',
                'postalCode' => '34560',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Lahn-Dill',
            'name' => 'Kaiserin-Auguste-Victoria Krankenhaus',
            'tier' => null,
            'size' => 'Small',
            'beds' => 97,
            'location' => 'Rural',
            'address' => [
                'street' => 'Stegwiese 27',
                'city' => 'Ehringshausen',
                'state' => 'Hessen',
                'postalCode' => '35630',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Offenbach',
            'name' => 'Ketteler Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 253,
            'location' => 'Urban',
            'address' => [
                'street' => 'Lichtenplattenweg 85',
                'city' => 'Offenbach am Main',
                'state' => 'Hessen',
                'postalCode' => '63071',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Hersfeld-Rotenburg',
            'name' => 'Klinikum Bad Hersfeld',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 568,
            'location' => 'Rural',
            'address' => [
                'street' => 'Seilerweg 29',
                'city' => 'Bad Hersfeld',
                'state' => 'Hessen',
                'postalCode' => '36251',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Darmstadt',
            'name' => 'Klinikum Darmstadt',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 879,
            'location' => 'Urban',
            'address' => [
                'street' => 'Grafenstraße 9',
                'city' => 'Darmstadt',
                'state' => 'Hessen',
                'postalCode' => '64283',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Lahn-Dill',
            'name' => 'Klinikum Dillenburg',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 261,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Rotebergstraße 2',
                'city' => 'Dillenburg',
                'state' => 'Hessen',
                'postalCode' => '35683',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Varisano Klinikum Frankfurt-Höchst',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 828,
            'location' => 'Urban',
            'address' => [
                'street' => 'Gotenstraße 6-8',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '65929',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Fulda',
            'name' => 'Klinikum Fulda',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 952,
            'location' => 'Urban',
            'address' => [
                'street' => 'Pacellialle 4',
                'city' => 'Fulda',
                'state' => 'Hessen',
                'postalCode' => '36043',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Main-Kinzig',
            'name' => 'Klinikum Hanau',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 727,
            'location' => 'Urban',
            'address' => [
                'street' => 'Leimenstraße 20',
                'city' => 'Hanau',
                'state' => 'Hessen',
                'postalCode' => '63450',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Kassel',
            'name' => 'Klinikum Kassel',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 1026,
            'location' => 'Urban',
            'address' => [
                'street' => 'Mönchebergstraße 41-43',
                'city' => 'Kassel',
                'state' => 'Hessen',
                'postalCode' => '34125',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Schwalm-Eder',
            'name' => 'Asklepios Klinikum Schwalmstadt',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 238,
            'location' => 'Rural',
            'address' => [
                'street' => 'Krankenhausstrasse 27',
                'city' => 'Schwalmstadt',
                'state' => 'Hessen',
                'postalCode' => '34613',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Lahn-Dill',
            'name' => 'Klinikum Wetzlar',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 654,
            'location' => 'Urban',
            'address' => [
                'street' => 'Forsthausstraße 1',
                'city' => 'Wetzlar',
                'state' => 'Hessen',
                'postalCode' => '35578',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Werra-Meißner',
            'name' => 'Klinikum Werra Meißner - Standort Eschwege',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 324,
            'location' => 'Rural',
            'address' => [
                'street' => 'Elsa-Brändström-Straße 1',
                'city' => 'Eschwege',
                'state' => 'Hessen',
                'postalCode' => '37269',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Werra-Meißner',
            'name' => 'Klinikum Werra Meißner - Standort Witzenhausen',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 125,
            'location' => 'Rural',
            'address' => [
                'street' => 'Steinstraße 18-26',
                'city' => 'Witzenhausen',
                'state' => 'Hessen',
                'postalCode' => '37213',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Waldeck-Frankenberg',
            'name' => 'Krankenhaus Bad Arolsen',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 103,
            'location' => 'Rural',
            'address' => [
                'street' => 'Große Allee 50',
                'city' => 'Bad Arolsen',
                'state' => 'Hessen',
                'postalCode' => '34454',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Main-Taunus',
            'name' => 'Krankenhaus Bad Soden',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 385,
            'location' => 'Urban',
            'address' => [
                'street' => 'Kronberger Straße 36',
                'city' => 'Bad Soden am Taunus',
                'state' => 'Hessen',
                'postalCode' => '65812',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Vogelsberg',
            'name' => 'Krankenhaus Eichhof Lauterbach',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 240,
            'location' => 'Rural',
            'address' => [
                'street' => 'Eichhofstraße 1',
                'city' => 'Lauterbach(Hessen)',
                'state' => 'Hessen',
                'postalCode' => '36341',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Main-Taunus',
            'name' => 'Krankenhaus Hofheim',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 165,
            'location' => 'Urban',
            'address' => [
                'street' => 'Lindenstraße 10',
                'city' => 'Hofheim am Taunus',
                'state' => 'Hessen',
                'postalCode' => '65719',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Krankenhaus Nordwest',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 438,
            'location' => 'Urban',
            'address' => [
                'street' => 'Steinbacher Hohl 2-26',
                'city' => 'Frankfurt',
                'state' => 'Hessen',
                'postalCode' => '60488',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Groß-Gerau',
            'name' => 'Kreisklinik Groß-Gerau',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 220,
            'location' => 'Urban',
            'address' => [
                'street' => 'Wilhelm-Seipp-Str. 3A',
                'city' => 'Groß-Gerau',
                'state' => 'Hessen',
                'postalCode' => '64521',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Darmstadt-Dieburg',
            'name' => 'Kreisklinik Groß-Umstadt',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 363,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Krankenhausstraße 11',
                'city' => 'Groß-Umstadt',
                'state' => 'Hessen',
                'postalCode' => '64823',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Kassel',
            'name' => 'Kreisklinik Hofgeismar',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 114,
            'location' => 'Rural',
            'address' => [
                'street' => 'Liebenauer Straße 1',
                'city' => 'Hofgeismar',
                'state' => 'Hessen',
                'postalCode' => '34369',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Kassel',
            'name' => 'Kreisklinik Wolfhagen',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 82,
            'location' => 'Rural',
            'address' => [
                'street' => 'Am Kleinen Ofenberg 1',
                'city' => 'Wolfhagen',
                'state' => 'Hessen',
                'postalCode' => '34466',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Vogelsberg',
            'name' => 'Kreiskrankenhaus Alsfeld',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 154,
            'location' => 'Rural',
            'address' => [
                'street' => 'Schwabenröder Straße 81',
                'city' => 'Alsfeld',
                'state' => 'Hessen',
                'postalCode' => '36304',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Bergstraße',
            'name' => 'Kreiskrankenhaus Bergstraße',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 260,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Viernheimer Straße 2',
                'city' => 'Heppenheim(Bergstraße)',
                'state' => 'Hessen',
                'postalCode' => '64646',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Odenwald',
            'name' => 'Kreiskrankenhaus Erbach',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 344,
            'location' => 'Rural',
            'address' => [
                'street' => 'Albert-Schweitzer-Straße 10',
                'city' => 'Erbach',
                'state' => 'Hessen',
                'postalCode' => '64711',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Waldeck-Frankenberg',
            'name' => 'Kreiskrankenhaus Frankenberg',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 223,
            'location' => 'Rural',
            'address' => [
                'street' => 'Forststraße 9',
                'city' => 'Frankenberg(Eder)',
                'state' => 'Hessen',
                'postalCode' => '35066',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Hersfeld-Rotenburg',
            'name' => 'Kreiskrankenhaus Rotenburg an der Fulda',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 168,
            'location' => 'Rural',
            'address' => [
                'street' => 'Am Emanuelsberg 1',
                'city' => 'Rotenburg an der Fulda',
                'state' => 'Hessen',
                'postalCode' => '36199',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Wetterau',
            'name' => 'Kreiskrankenhaus Schotten',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 142,
            'location' => 'Rural',
            'address' => [
                'street' => 'Wetterauer Platz 1',
                'city' => 'Schotten',
                'state' => 'Hessen',
                'postalCode' => '63679',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Limburg-Weilburg',
            'name' => 'Kreiskrankenhaus Weilburg',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 181,
            'location' => 'Rural',
            'address' => [
                'street' => 'Am Steinbühl 2',
                'city' => 'Weilburg',
                'state' => 'Hessen',
                'postalCode' => '35781',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Main-Kinzig',
            'name' => 'Main-Kinzig-Kliniken - Gelnhausen',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 441,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Herzbachweg 14',
                'city' => 'Gelnhausen',
                'state' => 'Hessen',
                'postalCode' => '63571',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Main-Kinzig',
            'name' => 'Main-Kinzig-Kliniken - Schlüchtern',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 276,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Kurfürstenstraße 17',
                'city' => 'Schlüchtern',
                'state' => 'Hessen',
                'postalCode' => '36381',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Kassel',
            'name' => 'Marienkrankenhaus Kassel',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 205,
            'location' => 'Urban',
            'address' => [
                'street' => 'Marburger Str. 85',
                'city' => 'Kassel',
                'state' => 'Hessen',
                'postalCode' => '34127',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Offenbach',
            'name' => 'Sana Klinikum Offenbach',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 824,
            'location' => 'Urban',
            'address' => [
                'street' => 'Starkenburgring 66',
                'city' => 'Offenbach am Main',
                'state' => 'Hessen',
                'postalCode' => '63069',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Sankt Katharinen-Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 248,
            'location' => 'Urban',
            'address' => [
                'street' => 'Seckbacher Landstraße 65',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60389',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'St. Elisabethen-Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 225,
            'location' => 'Urban',
            'address' => [
                'street' => 'Ginnheimer Straße 3',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60487',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Gießen',
            'name' => 'St. Josefs Krankenhaus',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 254,
            'location' => 'Urban',
            'address' => [
                'street' => 'Wilhelmstraße 7',
                'city' => 'Gießen',
                'state' => 'Hessen',
                'postalCode' => '35392',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Rheingau Taunus',
            'name' => 'St. Josefs-Hospital Rheingau',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 109,
            'location' => 'Rural',
            'address' => [
                'street' => 'Eibinger Straße 9',
                'city' => 'Rüdesheim am Rhein',
                'state' => 'Hessen',
                'postalCode' => '65385',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Wiesbaden',
            'name' => 'St. Josefs-Hospital Wiesbaden',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 505,
            'location' => 'Urban',
            'address' => [
                'street' => 'Beethovenstraße 20',
                'city' => 'Wiesbaden',
                'state' => 'Hessen',
                'postalCode' => '65189',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Bergstraße',
            'name' => 'St. Josef Krankenhaus Viernheim',
            'tier' => null,
            'size' => 'Small',
            'beds' => 76,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Seegartenstraße 4',
                'city' => 'Viernheim',
                'state' => 'Hessen',
                'postalCode' => '68519',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Bergstraße',
            'name' => 'St. Marien Krankenhaus Lampertheim',
            'tier' => 'Basic',
            'size' => 'Small',
            'beds' => 93,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Neue Schulstraße 12',
                'city' => 'Lampertheim',
                'state' => 'Hessen',
                'postalCode' => '68623',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Limburg-Weilburg',
            'name' => 'St. Vincenz-Krankenhaus Limburg',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 410,
            'location' => 'Mixed',
            'address' => [
                'street' => 'Auf dem Schafsberg 1',
                'city' => 'Limburg an der Lahn',
                'state' => 'Hessen',
                'postalCode' => '65549',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Main-Kinzig',
            'name' => 'St. Vinzenz-Krankenhaus Hanau',
            'tier' => 'Basic',
            'size' => 'Medium',
            'beds' => 306,
            'location' => 'Urban',
            'address' => [
                'street' => 'Am Frankfurter Tor 25',
                'city' => 'Hanau',
                'state' => 'Hessen',
                'postalCode' => '63450',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Waldeck-Frankenberg',
            'name' => 'Stadtkrankenhaus Korbach',
            'tier' => 'Extended',
            'size' => 'Medium',
            'beds' => 249,
            'location' => 'Rural',
            'address' => [
                'street' => 'Enser Straße 19',
                'city' => 'Korbach',
                'state' => 'Hessen',
                'postalCode' => '34497',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Frankfurt',
            'name' => 'Universitätsklinikum Frankfurt',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 1394,
            'location' => 'Urban',
            'address' => [
                'street' => 'Theodor-Stern-Kai 7',
                'city' => 'Frankfurt am Main',
                'state' => 'Hessen',
                'postalCode' => '60596',
                'country' => 'Deutschland',
            ],
            'participating' => false,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Gießen',
            'name' => 'Universitätsklinikum Gießen und Marburg, Standort Gießen',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 1282,
            'location' => 'Urban',
            'address' => [
                'street' => 'Rudolf-Buchheim-Straße 8',
                'city' => 'Gießen',
                'state' => 'Hessen',
                'postalCode' => '35392',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
        yield [
            'state' => 'Hessen',
            'area' => 'Marburg-Biedenkopf',
            'name' => 'Universitätsklinikum Gießen und Marburg, Standort Marburg',
            'tier' => 'Full',
            'size' => 'Large',
            'beds' => 944,
            'location' => 'Urban',
            'address' => [
                'street' => 'Baldingerstraße',
                'city' => 'Marburg',
                'state' => 'Hessen',
                'postalCode' => '35043',
                'country' => 'Deutschland',
            ],
            'participating' => true,
        ];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['hospital'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'hospital';
    }
}
