<?php

namespace App\Seed\Infrastructure\Provider;

use App\Allocation\Domain\Entity\Department;
use App\Seed\Application\Contracts\SeedProviderInterface;
use App\User\Domain\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * @implements SeedProviderInterface<string>
 */
#[AsTaggedItem('app.seed_provider')]
final class DepartmentSeedProvider implements SeedProviderInterface
{
    /**
     * @return iterable<Department>
     */
    #[\Override]
    public function build(User $user): iterable
    {
        foreach ($this->provide() as $name) {
            $entity = new Department()
                ->setName($name)
                ->setCreatedBy($user);

            yield $entity;
        }
    }

    /**
     * @return iterable<string>
     */
    #[\Override]
    public function provide(): iterable
    {
        yield 'Akut- und Gerontopsych. / Isolierung';
        yield 'Akut- und Gerontopsychiatrie';
        yield 'Allgemein Innere Medizin';
        yield 'Allgemein- und Viszeralchirurgie';
        yield 'Allgemeine Augenheilkunde';
        yield 'Allgemeine Augenheilkunde - Isolierung';
        yield 'Allgemeine Geriatrie - Isolierung';
        yield 'Allgemeine Kinderheilkunde - Isolierung';
        yield 'Allgemeine Neurologie - Isolierung';
        yield 'Allgemeine Urologie - Isolierung';
        yield 'Cardiac Arrest Center';
        yield 'Chest Pain Unit';
        yield 'Chir. Allgemein - Isolierung';
        yield 'Chir. IMC - Isolierung';
        yield 'Chir. IMC ohne Beatmung';
        yield 'Chir. Intensiv - Isolierung';
        yield 'Chir. Intensiv mit Beatmung';
        yield 'Chir. Intensiv mit Isolierung';
        yield 'Chir. Intensiv ohne Beatmung';
        yield 'CoVID-19 Intensivstation';
        yield 'CoVID-19 Normalstation';
        yield 'Endokrino-/Diabetologie';
        yield 'Endokrino-/Diabetologie - Isolierung';
        yield 'Gastroenterologie';
        yield 'Gastroenterologie - Isolierung';
        yield 'Geburtshilfe';
        yield 'Geburtshilfe - Isolierung';
        yield 'Gefäßchirurgie';
        yield 'Geriatrie';
        yield 'Gynäkologie';
        yield 'Gynäkologie - Isolierung';
        yield 'Hals-Nasen-Ohrenheilkunde';
        yield 'Hals-Nasen-Ohrenheilkunde - Isolierung';
        yield 'Handchirurgie';
        yield 'Haut- und Geschlechtsk. - Isolierung';
        yield 'Haut- und Geschlechtskrankheiten';
        yield 'Herzchirurgie';
        yield 'IMC';
        yield 'Infektiologie';
        yield 'Innere Allgemein - Isolierung';
        yield 'Innere IMC - Isolierung';
        yield 'Innere IMC ohne Beatmung';
        yield 'Innere Intensiv - Isolierung';
        yield 'Innere Intensiv mit Beatmung';
        yield 'Innere Intensiv ohne Beatmung';
        yield 'Innere Überwachung';
        yield 'Intensivstation';
        yield 'Intoxikation';
        yield 'Kardiologie';
        yield 'Kardiologie - Isolierung';
        yield 'Kardiologie Intensiv - Isolierung';
        yield 'Kardiologie Intensiv mit Beatmung';
        yield 'Kardiologie Intensiv ohne Beatmung';
        yield 'Kinder / Jugendpsychiatrie / Isolierung';
        yield 'Kinder Intensiv - Isolierung';
        yield 'Kinder Intensiv mit Beatmung';
        yield 'Kinder Intensiv ohne Beatmung';
        yield 'Kinder- und Jugendgynäkologie';
        yield 'Kinder-HNO';
        yield 'Kinder-Pneumologie';
        yield 'Kinderaugenheilkunde';
        yield 'Kinderchirurgie';
        yield 'Kinderdiabetologie';
        yield 'Kindergastroenterologie';
        yield 'Kinderheilkunde';
        yield 'Kinderinfektiologie';
        yield 'Kinderkardiologie';
        yield 'Kinderneurochirurgie';
        yield 'Kinderneurologie';
        yield 'Kinderpsychiatrie';
        yield 'Kinderurologie';
        yield 'Mund-Kiefer-Gesichtschirurgie';
        yield 'Mund-Kiefer-Gesichtschirurgie - Isolier';
        yield 'Neonatologie Intensiv';
        yield 'Neurochirurgie';
        yield 'Neurochirurgie Intensiv - Isolierung';
        yield 'Neurochirurgie Intensiv mit Beatmung';
        yield 'Neurochirurgie Intensiv ohne Beatmung';
        yield 'Neurologie';
        yield 'Neurologie Intensiv - Isolierung';
        yield 'Neurologie Intensiv mit Beatmung';
        yield 'Neurologie Intensiv ohne Beatmung';
        yield 'Nuklearmedizin';
        yield 'Operative Neurochirurgie - Isolierung';
        yield 'Orthopädie';
        yield 'Orthopädie - Isolierung';
        yield 'Pneumologie';
        yield 'Pneumologie - Isolierung';
        yield 'Psychiatrie';
        yield 'Psychiatrie / Psychother. / Isolierung';
        yield 'Psychiatrie HFEG';
        yield 'Sektorenzuweisung nach PSychKHG';
        yield 'Strahlentherapie';
        yield 'Stroke Unit';
        yield 'Suchtbehandlung';
        yield 'Thoraxchirurgie';
        yield 'Thrombektomie';
        yield 'Transport zu definierter Leistung';
        yield 'Traumatologisch Intensiv - Isolierung';
        yield 'Traumatologisch Intensiv mit Beatmung';
        yield 'Traumatologisch Intensiv ohne Beatmung';
        yield 'Unfallchirurgie';
        yield 'Unfallchirurgie - Isolierung';
        yield 'Urologie';
        yield 'Verbrennungschirurgie';
        yield 'Wirbelsäulenchirurgie';
        yield 'ZAS';
        yield 'ZOM';
        yield 'Zu- Verlegung Sonderlage Ukraine';
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function purgeTables(): array
    {
        return ['department'];
    }

    #[\Override]
    public function getType(): string
    {
        return 'department';
    }
}
