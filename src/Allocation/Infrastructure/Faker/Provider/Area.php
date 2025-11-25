<?php

namespace App\Allocation\Infrastructure\Faker\Provider;

use Faker\Provider\Base;

/** @psalm-suppress PropertyNotSetInConstructor */
final class Area extends Base
{
    /** @var string[] */
    protected static array $dispatchAreas = [
        'Allgäu', 'Amberg', 'Ansbach', 'Augsburg', 'Aachen', 'Altmark', 'Anhalt-Bitterfeld',
        'Bamberg-Forchheim', 'Bayreuth/Kulmbach', 'Bayerischer Untermain', 'Berlin', 'Brandenburg', 'Bergstraße', 'Braunschweig', 'Berga', 'Bielefeld', 'Bochum', 'Bonn', 'Borken', 'Bottrop', 'Börde', 'Burgenlandkreis', 'Bremen', 'Bremerhaven', 'Böblingen', 'Bodensee-Oberschwaben', 'Biberach',
        'Coburg', 'Celle', 'Cuxhaven', 'Coesfeld', 'Chemnitz', 'Calw',
        'Donau-Iller', 'Darmstadt', 'Darmstadt-Dieburg', 'Dietzenbach', 'Diepholz', 'Düsseldorf', 'Düsseldorf', 'Dortmund', 'Düren', 'Dresden', 'Dessau',
        'Erding', 'Emden', 'Ems-Vechte', 'Ennepe', 'Essen', 'Euskirchen', 'Eichsfeld', 'Erfurt', 'Esslingen', 'Emmendingen',
        'Fürstenfeldbruck', 'Frankfurt', 'Fulda', 'Friesland-Wilhelmshaven', 'Freudenstadt', 'Freiburg',
        'Gerau', 'Gießen', 'Geeste', 'Gifhorn', 'Göttingen', 'Goslar', 'Gelsenkirchen', 'Gütersloh', 'Gera', 'Gotha', 'Göppingen',
        'Hochfranken', 'Hochtaunus', 'Hersfeld-Rotenburg', 'Heidekreis', 'Hameln', 'Hannover', 'Harburg', 'Helmstedt', 'Hildesheim', 'Hagen', 'Hamm', 'Herford', 'Herne', 'Höxter', 'Heinsberg', 'Halle', 'Harz', 'Hamburg', 'Hohenlohe', 'Heilbronn', 'Heidelberg',
        'Ingolstadt', ' Ilmkreis',
        'Jerichower Land', 'Jena',
        'Kassel', 'Kaiser', 'Koblenz', 'Kreuznach', 'Kleve', 'Köln', 'Krefeld', 'Konstanz', 'Karlsruhe',
        'Landshut', 'Lausitz', 'Lahn-Dill', 'Limburg-Weilburg', 'Lüchow', 'Lüneburg', 'Ludwigshafen', 'Landau', 'Leverkusen', 'Lippe', 'Leipzig', 'Lübeck', 'Lörrach', 'Ludwigsburg',
        'Mittelfranken-Süd', 'München', 'Main-Taunus', 'Marburg-Biedenkopf', 'Main-Kinzig', 'Mitte', 'Mecklenburgische-Seenplatte', 'Mainz', 'Montabaur', 'Mark', 'Mettmann', 'Minden', 'Mühlheim', 'Münster', 'Magdeburg', 'Mansfeld-Südharz', 'Mitte', 'Mittelbaden', 'Mannheim', 'Main-Tauber',
        'Nordoberpfalz', 'Nürnberg', 'Nordost', 'Nordwest', 'Northeim', 'Nord', 'Neumünster', 'Nordhausen', 'Neckar-Odenwald',
        'Oberland', 'Oderland', 'Odenwald', 'Offenbach', 'Oldenburg', 'Osnabrück', 'Osterode', 'Ostfriesland', 'Oberberg', 'Oberhausen', 'Olpe', 'Ostsachsen', 'Ostalb', 'Ortenau',
        'Passau', 'Paderborn', 'Pforzheim',
        'Regensburg', 'Rosenheim', 'Rheingau-Taunus', 'Rostock', 'Rotenburg', 'Rhein-Erft Kreis', 'Rhein-Kreis-Neuss', 'Recklinghausen', 'Remscheid', 'Rhein-Sieg', 'Rottweil', 'Reutlingen', 'Rems-Murr', 'Rhein-Neckar',
        'Schweinfurt', 'Straubing', 'Traunstein', 'Schwalm-Eder', 'Salzgitter', 'Schaumburg', 'Stade', 'Siegen-Wittgenstein', 'Soest', 'Saar', 'Saalekreis', 'Salzlandkreis', 'Süd', 'Schmalkalden-Meiningen', 'Suhl', 'Schwarzwald-Baar', 'Stuttgart', 'Schwäbisch Hall',
        'Trier', 'Tuttlingen', 'Tübingen',
        'Uelzen', 'Unna', 'Unstrut-Hainich', 'Ulm',
        'Vogelsberg', ' Vorpommern-Rügen', 'Vorpommern-Greifswald', 'Vechta', 'Viersen',
        'Würzburg', 'Wetterau', 'Waldeck-Frankenberg', 'Werra-Meißner', 'Wiesbaden', 'Westmecklenburg', 'Wolfsburg', 'Worms', 'Warendorf', 'Wesel', 'Wupper', 'Wittenberg', 'Wartburgkreis', 'Weimarer Land', 'Waldshut',
        'Zwickau', 'Zollernalb',
    ];

    public static function dispatchArea(): string
    {
        return static::randomElement(static::$dispatchAreas);
    }
}
