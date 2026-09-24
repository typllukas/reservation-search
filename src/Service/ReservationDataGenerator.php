<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\ReservationSource;
use App\Enum\ReservationStatus;
use App\Enum\RoomKind;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use LogicException;
use Symfony\Component\Uid\Ulid;

use function array_chunk;
use function array_fill;
use function array_fill_keys;
use function array_keys;
use function array_sum;
use function count;
use function explode;
use function iconv;
use function implode;
use function in_array;
use function intdiv;
use function mt_rand;
use function mt_srand;
use function preg_replace;
use function sprintf;
use function strtolower;

/**
 * Dev seeder, written with DBAL: no callbacks, no cascades, no type conversion.
 */
final readonly class ReservationDataGenerator
{
    private const int INSERT_BATCH_SIZE = 1000;

    private const int RANDOM_SEED = 20260825;

    private const array CZECH_MALE_LAST_NAME_WEIGHTS = [
        'Novák' => 68,
        'Svoboda' => 52,
        'Novotný' => 50,
        'Dvořák' => 46,
        'Černý' => 35,
        'Procházka' => 32,
        'Kučera' => 30,
        'Veselý' => 26,
        'Horák' => 25,
        'Němec' => 24,
        'Marek' => 21,
        'Pokorný' => 20,
        'Pospíšil' => 20,
        'Hájek' => 19,
        'Jelínek' => 19,
        'Král' => 18,
        'Růžička' => 18,
        'Beneš' => 17,
        'Fiala' => 17,
        'Sedláček' => 16,
        'Doležal' => 16,
        'Zeman' => 15,
        'Kolář' => 15,
        'Navrátil' => 15,
        'Čermák' => 14,
        'Vaněk' => 14,
        'Urban' => 14,
        'Blažek' => 13,
        'Kříž' => 13,
        'Kovář' => 13,
        'Bartoš' => 12,
        'Vlček' => 12,
        'Polák' => 12,
        'Musil' => 11,
        'Kopecký' => 11,
        'Šimek' => 11,
        'Konečný' => 11,
        'Malý' => 10,
        'Holub' => 10,
        'Štěpánek' => 10,
    ];

    private const array CZECH_FEMALE_LAST_NAME_WEIGHTS = [
        'Nováková' => 68,
        'Svobodová' => 52,
        'Novotná' => 50,
        'Dvořáková' => 46,
        'Černá' => 35,
        'Procházková' => 32,
        'Kučerová' => 30,
        'Veselá' => 26,
        'Horáková' => 25,
        'Němcová' => 24,
        'Marková' => 21,
        'Pokorná' => 20,
        'Pospíšilová' => 20,
        'Hájková' => 19,
        'Jelínková' => 19,
        'Králová' => 18,
        'Růžičková' => 18,
        'Benešová' => 17,
        'Fialová' => 17,
        'Sedláčková' => 16,
        'Doležalová' => 16,
        'Zemanová' => 15,
        'Kolářová' => 15,
        'Navrátilová' => 15,
        'Čermáková' => 14,
        'Vaňková' => 14,
        'Urbanová' => 14,
        'Blažková' => 13,
        'Křížová' => 13,
        'Kovářová' => 13,
        'Bartošová' => 12,
        'Vlčková' => 12,
        'Poláková' => 12,
        'Musilová' => 11,
        'Kopecká' => 11,
        'Šimková' => 11,
        'Konečná' => 11,
        'Malá' => 10,
        'Holubová' => 10,
        'Štěpánková' => 10,
    ];

    private const int RARE_LAST_NAME_WEIGHT = 10;

    private const array CZECH_MALE_RARE_LAST_NAMES = [
        'Ambrož',
        'Bárta',
        'Bareš',
        'Bednář',
        'Bečka',
        'Bělík',
        'Beran',
        'Bezděk',
        'Bílek',
        'Blecha',
        'Bouček',
        'Brabec',
        'Brož',
        'Buchta',
        'Burda',
        'Cvrček',
        'Čech',
        'Čejka',
        'Čížek',
        'Daněk',
        'Dostál',
        'Drábek',
        'Dudek',
        'Duška',
        'Fencl',
        'Fořt',
        'Franěk',
        'Gregor',
        'Hanák',
        'Hanuš',
        'Havel',
        'Havlík',
        'Hejda',
        'Hejl',
        'Hladík',
        'Hlaváček',
        'Hofman',
        'Holeček',
        'Homola',
        'Horníček',
        'Hošek',
        'Houška',
        'Hrabal',
        'Hradil',
        'Hrubý',
        'Hruška',
        'Hudec',
        'Hynek',
        'Chalupa',
        'Chvojka',
        'Janda',
        'Janeček',
        'Janoušek',
        'Jirásek',
        'Jiroušek',
        'Kadlec',
        'Kalina',
        'Kalous',
        'Kaplan',
        'Karásek',
        'Kašpar',
        'Klíma',
        'Kliment',
        'Knotek',
        'Kohout',
        'Kolman',
        'Komárek',
        'Kopřiva',
        'Kordík',
        'Kosina',
        'Košťál',
        'Kotek',
        'Koudelka',
        'Kozel',
        'Krajíček',
        'Krátký',
        'Kraus',
        'Krejčík',
        'Kroupa',
        'Kubát',
        'Kubíček',
        'Kubík',
        'Kuchař',
        'Kulhánek',
        'Kunc',
        'Kutil',
        'Kvapil',
        'Lang',
        'Laštovka',
        'Lavička',
        'Ledvina',
        'Liška',
        'Lukeš',
        'Macek',
        'Mach',
        'Macháček',
        'Málek',
        'Mareš',
        'Marounek',
        'Maršálek',
        'Matoušek',
        'Mayer',
        'Mazánek',
        'Melichar',
        'Mikeš',
        'Mikulášek',
        'Mlčoch',
        'Moravec',
        'Mrázek',
        'Nedvěd',
        'Nekvasil',
        'Nešpor',
        'Nosek',
        'Ondráček',
        'Opat',
        'Osička',
        'Otradovec',
        'Pácal',
        'Palán',
        'Papež',
        'Pařízek',
        'Pavelka',
        'Pavlík',
        'Pecha',
        'Pelikán',
        'Petrášek',
        'Pícha',
        'Pilař',
        'Plachý',
        'Plšek',
        'Podzimek',
        'Pohl',
        'Poláček',
        'Ptáček',
        'Rada',
        'Rambousek',
        'Rejman',
        'Richter',
        'Rosa',
        'Rychlík',
        'Řehák',
        'Říha',
        'Sadílek',
        'Skála',
        'Sladký',
        'Slavík',
        'Smejkal',
        'Sobotka',
        'Souček',
        'Soukup',
        'Stehlík',
        'Strnad',
        'Suchý',
        'Sýkora',
        'Šafránek',
        'Šebesta',
        'Šedivý',
        'Škoda',
        'Šmíd',
        'Šnajdr',
        'Špaček',
        'Šťastný',
        'Štefan',
        'Šulc',
        'Švec',
        'Tesař',
        'Tichý',
        'Tomášek',
        'Tůma',
        'Uhlíř',
        'Vacek',
        'Valenta',
        'Vávra',
        'Vejvoda',
        'Vilímek',
        'Vinš',
        'Vítek',
        'Vlach',
        'Vlasák',
        'Vodička',
        'Vondra',
        'Vopálka',
        'Vorel',
        'Vrána',
        'Zábranský',
        'Zajíc',
        'Zedník',
        'Zima',
        'Zítka',
        'Zvěřina',
        'Žák',
        'Žižka',
    ];

    private const array CZECH_FEMALE_RARE_LAST_NAMES = [
        'Ambrožová',
        'Bártová',
        'Barešová',
        'Bednářová',
        'Bečková',
        'Bělíková',
        'Beranová',
        'Bezděková',
        'Bílková',
        'Blechová',
        'Boučková',
        'Brabcová',
        'Brožová',
        'Buchtová',
        'Burdová',
        'Cvrčková',
        'Čechová',
        'Čejková',
        'Čížková',
        'Daňková',
        'Dostálová',
        'Drábková',
        'Dudková',
        'Dušková',
        'Fenclová',
        'Fořtová',
        'Fraňková',
        'Gregorová',
        'Hanáková',
        'Hanušová',
        'Havlová',
        'Havlíková',
        'Hejdová',
        'Hejlová',
        'Hladíková',
        'Hlaváčková',
        'Hofmanová',
        'Holečková',
        'Homolová',
        'Horníčková',
        'Hošková',
        'Houšková',
        'Hrabalová',
        'Hradilová',
        'Hrubá',
        'Hrušková',
        'Hudcová',
        'Hynková',
        'Chalupová',
        'Chvojková',
        'Jandová',
        'Janečková',
        'Janoušková',
        'Jirásková',
        'Jiroušková',
        'Kadlecová',
        'Kalinová',
        'Kalousová',
        'Kaplanová',
        'Karásková',
        'Kašparová',
        'Klímová',
        'Klimentová',
        'Knotková',
        'Kohoutová',
        'Kolmanová',
        'Komárková',
        'Kopřivová',
        'Kordíková',
        'Kosinová',
        'Košťálová',
        'Kotková',
        'Koudelková',
        'Kozlová',
        'Krajíčková',
        'Krátká',
        'Krausová',
        'Krejčíková',
        'Kroupová',
        'Kubátová',
        'Kubíčková',
        'Kubíková',
        'Kuchařová',
        'Kulhánková',
        'Kuncová',
        'Kutilová',
        'Kvapilová',
        'Langová',
        'Laštovková',
        'Lavičková',
        'Ledvinová',
        'Lišková',
        'Lukešová',
        'Macková',
        'Machová',
        'Macháčková',
        'Málková',
        'Marešová',
        'Marounková',
        'Maršálková',
        'Matoušková',
        'Mayerová',
        'Mazánková',
        'Melicharová',
        'Mikešová',
        'Mikulášková',
        'Mlčochová',
        'Moravcová',
        'Mrázková',
        'Nedvědová',
        'Nekvasilová',
        'Nešporová',
        'Nosková',
        'Ondráčková',
        'Opatová',
        'Osičková',
        'Otradovcová',
        'Pácalová',
        'Palánová',
        'Papežová',
        'Pařízková',
        'Pavelková',
        'Pavlíková',
        'Pechová',
        'Pelikánová',
        'Petrášková',
        'Píchová',
        'Pilařová',
        'Plachá',
        'Plšková',
        'Podzimková',
        'Pohlová',
        'Poláčková',
        'Ptáčková',
        'Radová',
        'Rambousková',
        'Rejmanová',
        'Richterová',
        'Rosová',
        'Rychlíková',
        'Řeháková',
        'Říhová',
        'Sadílková',
        'Skálová',
        'Sladká',
        'Slavíková',
        'Smejkalová',
        'Sobotková',
        'Součková',
        'Soukupová',
        'Stehlíková',
        'Strnadová',
        'Suchá',
        'Sýkorová',
        'Šafránková',
        'Šebestová',
        'Šedivá',
        'Škodová',
        'Šmídová',
        'Šnajdrová',
        'Špačková',
        'Šťastná',
        'Štefanová',
        'Šulcová',
        'Švecová',
        'Tesařová',
        'Tichá',
        'Tomášková',
        'Tůmová',
        'Uhlířová',
        'Vacková',
        'Valentová',
        'Vávrová',
        'Vejvodová',
        'Vilímková',
        'Vinšová',
        'Vítková',
        'Vlachová',
        'Vlasáková',
        'Vodičková',
        'Vondrová',
        'Vopálková',
        'Vorlová',
        'Vránová',
        'Zábranská',
        'Zajícová',
        'Zedníková',
        'Zimová',
        'Zítková',
        'Zvěřinová',
        'Žáková',
        'Žižková',
    ];

    private const array FOREIGN_MALE_LAST_NAME_PHONE_PREFIXES = [
        'Müller' => '+49',
        'Schmidt' => '+49',
        'Weber' => '+49',
        'Fischer' => '+49',
        'Becker' => '+49',
        'Hoffmann' => '+49',
        'Schäfer' => '+49',
        'Koch' => '+49',
        'Klein' => '+49',
        'Wolf' => '+49',
        'Neumann' => '+49',
        'Zimmermann' => '+49',
        'Wagner' => '+43',
        'Gruber' => '+43',
        'Huber' => '+43',
        'Steiner' => '+43',
        'Moser' => '+43',
        'Leitner' => '+43',
        'Kowalski' => '+48',
        'Nowak' => '+48',
        'Wójcik' => '+48',
        'Kamiński' => '+48',
        'Lewandowski' => '+48',
        'Zieliński' => '+48',
        'Rossi' => '+39',
        'Ferrari' => '+39',
        'Russo' => '+39',
        'Esposito' => '+39',
        'Bianchi' => '+39',
        'Romano' => '+39',
        'Smith' => '+44',
        'Wilson' => '+44',
        'Brown' => '+44',
        'Taylor' => '+44',
        'Davies' => '+44',
        'Evans' => '+44',
        'Jansen' => '+31',
        'Bakker' => '+31',
        'Smit' => '+31',
        'Visser' => '+31',
        'Meijer' => '+31',
        'Horváth' => '+36',
        'Nagy' => '+36',
        'Tóth' => '+36',
        'Kovács' => '+36',
        'Szabó' => '+36',
        'Kováč' => '+421',
        'Varga' => '+421',
        'Baláž' => '+421',
        'Molnár' => '+421',
        'Melnyk' => '+380',
        'Shevchenko' => '+380',
        'Kovalenko' => '+380',
        'Bondarenko' => '+380',
        'Dubois' => '+33',
        'Bernard' => '+33',
        'Moreau' => '+33',
        'Lefèvre' => '+33',
        'García' => '+34',
        'Martínez' => '+34',
        'López' => '+34',
    ];

    private const array FOREIGN_FEMALE_LAST_NAME_PHONE_PREFIXES = [
        'Müller' => '+49',
        'Schmidt' => '+49',
        'Weber' => '+49',
        'Fischer' => '+49',
        'Becker' => '+49',
        'Hoffmann' => '+49',
        'Schäfer' => '+49',
        'Koch' => '+49',
        'Klein' => '+49',
        'Wolf' => '+49',
        'Neumann' => '+49',
        'Zimmermann' => '+49',
        'Wagner' => '+43',
        'Gruber' => '+43',
        'Huber' => '+43',
        'Steiner' => '+43',
        'Moser' => '+43',
        'Leitner' => '+43',
        'Kowalska' => '+48',
        'Nowak' => '+48',
        'Wójcik' => '+48',
        'Kamińska' => '+48',
        'Lewandowska' => '+48',
        'Zielińska' => '+48',
        'Rossi' => '+39',
        'Ferrari' => '+39',
        'Russo' => '+39',
        'Esposito' => '+39',
        'Bianchi' => '+39',
        'Romano' => '+39',
        'Smith' => '+44',
        'Wilson' => '+44',
        'Brown' => '+44',
        'Taylor' => '+44',
        'Davies' => '+44',
        'Evans' => '+44',
        'Jansen' => '+31',
        'Bakker' => '+31',
        'Smit' => '+31',
        'Visser' => '+31',
        'Meijer' => '+31',
        'Horváth' => '+36',
        'Nagy' => '+36',
        'Tóth' => '+36',
        'Kovács' => '+36',
        'Szabó' => '+36',
        'Kováčová' => '+421',
        'Vargová' => '+421',
        'Balážová' => '+421',
        'Molnárová' => '+421',
        'Melnyk' => '+380',
        'Shevchenko' => '+380',
        'Kovalenko' => '+380',
        'Bondarenko' => '+380',
        'Dubois' => '+33',
        'Bernard' => '+33',
        'Moreau' => '+33',
        'Lefèvre' => '+33',
        'García' => '+34',
        'Martínez' => '+34',
        'López' => '+34',
    ];

    private const array CZECH_MALE_FIRST_NAMES = [
        'Jan',
        'Jiří',
        'Petr',
        'Josef',
        'Pavel',
        'Martin',
        'Tomáš',
        'Jaroslav',
        'Miroslav',
        'Zdeněk',
        'František',
        'Václav',
        'Michal',
        'Milan',
        'Lukáš',
        'David',
        'Ondřej',
        'Jakub',
        'Marek',
        'Filip',
        'Vojtěch',
        'Adam',
        'Radek',
        'Daniel',
    ];

    private const array CZECH_FEMALE_FIRST_NAMES = [
        'Jana',
        'Marie',
        'Eva',
        'Hana',
        'Anna',
        'Lenka',
        'Kateřina',
        'Věra',
        'Lucie',
        'Alena',
        'Petra',
        'Jitka',
        'Veronika',
        'Michaela',
        'Tereza',
        'Martina',
        'Zuzana',
        'Barbora',
        'Kristýna',
        'Klára',
        'Markéta',
        'Adéla',
        'Nikola',
        'Denisa',
    ];

    private const array FOREIGN_MALE_FIRST_NAMES = [
        'Hans',
        'Thomas',
        'Andreas',
        'Stefan',
        'Piotr',
        'Marco',
        'Luca',
        'James',
        'Oliver',
        'Sven',
        'Lars',
        'Attila',
        'Dmytro',
        'Peter',
        'Pierre',
        'Javier',
        'Bram',
        'Jozef',
    ];

    private const array FOREIGN_FEMALE_FIRST_NAMES = [
        'Sabine',
        'Julia',
        'Ingrid',
        'Katarzyna',
        'Giulia',
        'Sofia',
        'Emma',
        'Laura',
        'Astrid',
        'Elena',
        'Marta',
        'Hanna',
        'Nina',
        'Ilona',
        'Camille',
        'Lucía',
        'Femke',
        'Zsófia',
    ];

    private const array ORIGIN_WEIGHTS = [
        'czech' => 69,
        'foreign' => 31,
    ];

    private const string CZECH_PHONE_PREFIX = '+420';

    /**
     * 30 names x 7 cities is 210, so with 220 hotels ten names repeat; the hotel facet keyed by ULID
     * rests on that.
     */
    private const array HOTEL_NAMES = [
        'Hotel Vltava',
        'Penzion Nová Ves',
        'Grandhotel',
        'Hotel Slunce',
        'Hotel Panorama',
        'Penzion U Lípy',
        'Hotel Zlatá Husa',
        'Penzion U Tří Kaprů',
        'Hotel Beskyd',
        'Penzion Na Kopečku',
        'Hotel Morava',
        'Hotel Continental',
        'Penzion U Zeleného Stromu',
        'Hotel Diamant',
        'Hotel Perla',
        'Penzion Pod Hradem',
        'Hotel Palác',
        'Penzion U Vodníka',
        'Hotel Sever',
        'Hotel Astoria',
        'Penzion U Kovárny',
        'Hotel Bílý Kůň',
        'Hotel Vyhlídka',
        'Penzion U Rybníka',
        'Hotel Jelen',
        'Hotel Merkur',
        'Penzion U Svatého Václava',
        'Hotel Alfa',
        'Penzion U Studánky',
        'Hotel Modrá Hvězda',
    ];

    private const array HOTEL_CHAINS = ['Vltava Group', 'Moravia Hotels', 'Bohemia Resorts'];

    private const array HOTEL_CITIES = [
        'Praha',
        'Brno',
        'Ostrava',
        'Plzeň',
        'Olomouc',
        'Český Krumlov',
        'Karlovy Vary',
    ];

    private const array NOTE_WEIGHTS = [
        'none' => 70,
        'short' => 25,
        'long' => 5,
    ];

    private const array SHORT_NOTES = [
        'Pozdní příjezd po 22. hodině.',
        'Platí kartou na místě.',
        'Alergie na peří.',
        'Prosí pokoj v přízemí.',
        'Nutný bezbariérový přístup.',
        'Snídaně bez lepku.',
        'Parkování pro dodávku.',
        'Přijede s malým psem.',
        'Vegetariánská strava.',
        'Dětská postýlka na pokoj.',
        'Fakturovat na firmu.',
        'Svatební cesta.',
        'Tichý pokoj do dvora.',
        'Nekuřácké patro.',
        'Brzký odjezd v pět ráno.',
        'Žádá výhled na řeku.',
    ];

    private const array LONG_NOTES = [
        'Host cestuje se dvěma malými dětmi, na pokoj prosím přidat postýlku a na recepci připravit ohřívač lahví.',
        'Firemní akce pro dvanáct lidí, večeře v salonku od sedmi hodin, fakturace na IČO uvedené v objednávce.',
        'Klient volal, že dorazí až v noci kolem druhé hodiny, prosí o ponechání klíče u nočního recepčního.',
        'Kvůli alergii na peří vyměňte prosím veškeré polštáře i přikrývky, host to řešil už při minulém pobytu.',
        'Svatební cesta, na pokoj připravit sekt a květiny, novomanželé dorazí v sobotu odpoledne po obřadu.',
        'Host má omezenou pohyblivost, potřebuje pokoj blízko výtahu a bezbariérovou koupelnu se sprchovým koutem.',
        'Skupina lyžařů, potřebují uzamykatelnou lyžárnu a možnost sušení bot, přijedou v pátek pozdě večer.',
        'Stálý klient, obvykle dostává pokoj s výhledem do zahrady, pokud bude volný, rezervujte mu ho i tentokrát.',
    ];

    private const array ENGLISH_SHORT_NOTES = [
        'Late arrival after 10 pm.',
        'Pays by card on arrival.',
        'Allergic to feathers.',
        'Asks for a ground floor room.',
        'Needs wheelchair access.',
        'Gluten-free breakfast.',
        'Parking for a van.',
        'Travelling with a small dog.',
        'Vegetarian meals.',
        'Baby cot in the room.',
        'Invoice to the company.',
        'Honeymoon.',
        'Quiet room facing the courtyard.',
        'Non-smoking floor.',
        'Early departure at five in the morning.',
        'Would like a river view.',
    ];

    private const array ENGLISH_LONG_NOTES = [
        'The guest travels with two small children, please add a cot and have a bottle warmer ready at reception.',
        'Company event for twelve people, dinner in the lounge from seven, invoice to the VAT number in the order.',
        'The client called to say they arrive around two in the morning, please leave the key with the night porter.',
        'Feather allergy, please replace all pillows and duvets, the guest raised this during the last stay too.',
        'Honeymoon, please prepare sparkling wine and flowers, the couple arrives on Saturday after the ceremony.',
        'The guest has limited mobility and needs a room close to the lift with an accessible walk-in shower.',
        'A group of skiers, they need a lockable ski room and somewhere to dry boots, arriving late on Friday evening.',
        'A regular guest who usually gets a room facing the garden, if one is free please book it for them again.',
    ];

    private const array STATUS_WEIGHTS = [
        ReservationStatus::CONFIRMED->value => 72,
        ReservationStatus::CANCELLED->value => 18,
        ReservationStatus::PENDING->value => 10,
    ];

    private const array SOURCE_WEIGHTS = [
        ReservationSource::WEB->value => 55,
        ReservationSource::TRAVEL_AGENCY->value => 30,
        ReservationSource::PHONE->value => 15,
    ];

    private const array NIGHT_WEIGHTS = [
        1 => 22,
        2 => 26,
        3 => 18,
        4 => 11,
        5 => 7,
        6 => 5,
        7 => 4,
        8 => 2,
        9 => 1,
        10 => 1,
        11 => 1,
        12 => 1,
        13 => 1,
        14 => 1,
    ];

    /** haléřů / night */
    private const int GUARANTEED_ROOM_RATE = 250000;

    /** haléřů / night */
    private const array NIGHTLY_RATES = [
        RoomKind::SINGLE->value => 120000,
        RoomKind::DOUBLE->value => 190000,
        RoomKind::APARTMENT->value => 310000,
        RoomKind::FAMILY->value => 260000,
    ];

    private const array PAID_WEIGHTS = [
        ReservationStatus::CONFIRMED->value => [
            1 => 88,
            0 => 12,
        ],
        ReservationStatus::PENDING->value => [
            1 => 4,
            0 => 96,
        ],
        ReservationStatus::CANCELLED->value => [
            1 => 35,
            0 => 65,
        ],
    ];

    private const array HOTEL_COLUMNS = ['id', 'code', 'name', 'chain', 'city', 'created_at', 'updated_at'];

    private const array GUEST_COLUMNS = ['id', 'name', 'email', 'phone', 'created_at', 'updated_at'];

    private const array RESERVATION_COLUMNS = [
        'id',
        'number',
        'status',
        'source',
        'arrival',
        'departure',
        'total_price',
        'paid',
        'note',
        'created_at',
        'updated_at',
        'guest_id',
        'hotel_id',
    ];

    private const array ROOM_COLUMNS = ['id', 'kind', 'guest_count', 'price', 'reservation_id', 'created_at'];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param callable(string): void $reportProgress
     */
    public function generate(int $hotelCount, int $guestCount, int $reservationCount, callable $reportProgress): void
    {
        mt_srand(self::RANDOM_SEED);

        $hotelIds = $this->generateHotels($hotelCount);
        $reportProgress(sprintf('hotels: %d', $hotelCount));

        $isForeignByGuestId = $this->generateGuests($guestCount);
        $reportProgress(sprintf('guests: %d', $guestCount));

        $firstRandomNumber = $this->generateGuaranteedCases($hotelIds[0], $reportProgress);
        $this->generateReservations(
            $firstRandomNumber,
            $reservationCount,
            $hotelIds,
            $isForeignByGuestId,
            $reportProgress,
        );
    }

    /**
     * @return array<int, string> binary ULIDs
     */
    private function generateHotels(int $hotelCount): array
    {
        $ids = [];
        $hotelRows = [];
        $now = $this->formatCurrentTimestamp();

        for ($hotelIndex = 0; $hotelIndex < $hotelCount; ++$hotelIndex) {
            $id = new Ulid()->toBinary();
            $ids[] = $id;
            $city = self::HOTEL_CITIES[$hotelIndex % count(self::HOTEL_CITIES)];
            $hotelRows[] = [
                $id,
                sprintf('H-%04d', $hotelIndex + 1),
                self::HOTEL_NAMES[$hotelIndex % count(self::HOTEL_NAMES)] . ' ' . $city,
                self::HOTEL_CHAINS[$hotelIndex % count(self::HOTEL_CHAINS)],
                $city,
                $now,
                $now,
            ];
        }

        $this->insert('hotel', self::HOTEL_COLUMNS, $hotelRows, [0]);

        return $ids;
    }

    /**
     * @return array<string, bool> binary ULID => whether the guest is foreign
     */
    private function generateGuests(int $guestCount): array
    {
        $isForeignByGuestId = [];
        $guestRows = [];

        for ($guestIndex = 0; $guestIndex < $guestCount; ++$guestIndex) {
            $id = new Ulid()->toBinary();
            [$guestRow, $isForeign] = $this->buildRandomGuestRow($id, $guestIndex);
            $isForeignByGuestId[$id] = $isForeign;
            $guestRows[] = $guestRow;

            if (count($guestRows) < self::INSERT_BATCH_SIZE) {
                continue;
            }

            $this->insert('guest', self::GUEST_COLUMNS, $guestRows, [0]);
            $guestRows = [];
        }

        if ($guestRows !== []) {
            $this->insert('guest', self::GUEST_COLUMNS, $guestRows, [0]);
        }

        return $isForeignByGuestId;
    }

    /**
     * The cases the tests and measurements rest on.
     *
     * @param callable(string): void $reportProgress
     *
     * @return int the number the random reservations continue from
     */
    private function generateGuaranteedCases(string $hotelId, callable $reportProgress): int
    {
        $names = ['Novák Jan', 'Nováková Petra', 'Nováková Jana', 'Novotný Jan', 'Svoboda Marek'];
        $guestIds = [];
        $guestRows = [];
        foreach ($names as $guestIndex => $name) {
            $id = new Ulid()->toBinary();
            $guestIds[$name] = $id;
            $guestRows[] = $this->buildGuestRow($id, $name, 900000 + $guestIndex, self::CZECH_PHONE_PREFIX);
        }

        $this->insert('guest', self::GUEST_COLUMNS, $guestRows, [0]);

        $arrival = new DateTimeImmutable('2026-09-14');
        $cases = [
            // 1-4: same-name guests that show the names did not go through a stemmer
            ['Novák Jan', 'Pozdní příjezd, platí kartou na místě.', [RoomKind::DOUBLE]],
            ['Nováková Petra', 'Alergie na peří, prosím jiné polštáře.', [RoomKind::SINGLE]],
            // 3: an English note, found across its word forms only through note.english
            ['Nováková Jana', 'Late arrival, guest asked about the reservations for next year.', [RoomKind::APARTMENT]],
            ['Novotný Jan', 'Rezervace potvrzena telefonicky.', [RoomKind::DOUBLE]],
            // 5: the match is in the note only; without boosting it outranks the real same-name guests
            ['Svoboda Marek', 'Nováková volala kvůli rezervacím na příští rok.', [RoomKind::DOUBLE]],
            // 6: two identical double rooms; without reverse_nested the facet counts it twice
            ['Novák Jan', 'Firemní akce, fakturace na IČO.', [RoomKind::DOUBLE, RoomKind::DOUBLE]],
        ];

        $reservationRows = [];
        $roomRows = [];
        foreach ($cases as $caseIndex => [$guestName, $note, $kinds]) {
            $reservationId = new Ulid()->toBinary();
            $totalPrice = 0;
            foreach ($kinds as $kind) {
                $totalPrice += self::GUARANTEED_ROOM_RATE;
                $roomRows[] = [
                    new Ulid()->toBinary(),
                    $kind->value,
                    2,
                    self::GUARANTEED_ROOM_RATE,
                    $reservationId,
                    $this->formatCurrentTimestamp(),
                ];
            }

            $reservationRows[] = [
                $reservationId,
                sprintf('2026-%06d', $caseIndex + 1),
                ReservationStatus::CONFIRMED->value,
                ReservationSource::TRAVEL_AGENCY->value,
                $arrival->format('Y-m-d'),
                $arrival->modify('+3 days')->format('Y-m-d'),
                $totalPrice,
                1,
                $note,
                $this->formatCurrentTimestamp(),
                $this->formatCurrentTimestamp(),
                $guestIds[$guestName],
                $hotelId,
            ];
        }

        $this->insert('reservation', self::RESERVATION_COLUMNS, $reservationRows, [0, 11, 12]);
        $this->insert('reservation_room', self::ROOM_COLUMNS, $roomRows, [0, 4]);
        $reportProgress(sprintf(
            'guaranteed cases: %d reservations (2026-000001 to 2026-%06d)',
            count($cases),
            count($cases),
        ));

        return count($cases) + 1;
    }

    /**
     * @param array<int, string> $hotelIds
     * @param array<string, bool> $isForeignByGuestId binary ULID => whether the guest is foreign
     * @param callable(string): void $reportProgress
     */
    private function generateReservations(
        int $firstNumber,
        int $reservationCount,
        array $hotelIds,
        array $isForeignByGuestId,
        callable $reportProgress,
    ): void {
        $kinds = RoomKind::cases();
        $now = $this->formatCurrentTimestamp();
        $firstDay = new DateTimeImmutable('2026-01-01');

        $guestIds = array_keys($isForeignByGuestId);
        $reservationRows = [];
        $roomRows = [];
        $writtenReservations = 0;

        for ($reservationIndex = 0; $reservationIndex < $reservationCount; ++$reservationIndex) {
            $reservationId = new Ulid()->toBinary();
            $arrival = $firstDay->modify(sprintf('+%d days', mt_rand(0, 729)));
            $nights = $this->pickWeighted(self::NIGHT_WEIGHTS);
            $status = $this->pickWeighted(self::STATUS_WEIGHTS);
            $roomCount = mt_rand(1, 3);

            $totalPrice = 0;
            for ($roomIndex = 0; $roomIndex < $roomCount; ++$roomIndex) {
                $kind = $kinds[mt_rand(0, count($kinds) - 1)];
                $price = intdiv(self::NIGHTLY_RATES[$kind->value] * $nights * mt_rand(85, 115), 100);
                $totalPrice += $price;
                $roomRows[] = [
                    new Ulid()->toBinary(),
                    $kind->value,
                    mt_rand(1, 4),
                    $price,
                    $reservationId,
                    $now,
                ];
            }

            $noteKind = $this->pickWeighted(self::NOTE_WEIGHTS);
            $noteIndex = match ($noteKind) {
                'short' => mt_rand(0, count(self::SHORT_NOTES) - 1),
                'long' => mt_rand(0, count(self::LONG_NOTES) - 1),
                'none' => null,
            };
            $source = $this->pickWeighted(self::SOURCE_WEIGHTS);
            $paid = $this->pickWeighted(self::PAID_WEIGHTS[$status]);
            $guestId = $guestIds[mt_rand(0, count($guestIds) - 1)];
            $hotelId = $hotelIds[mt_rand(0, count($hotelIds) - 1)];

            $reservationRows[] = [
                $reservationId,
                sprintf('2026-%06d', $firstNumber + $reservationIndex),
                $status,
                $source,
                $arrival->format('Y-m-d'),
                $arrival->modify(sprintf('+%d days', $nights))->format('Y-m-d'),
                $totalPrice,
                $paid,
                $this->pickNoteText($noteKind, $noteIndex, $isForeignByGuestId[$guestId]),
                $now,
                $now,
                $guestId,
                $hotelId,
            ];

            if (count($reservationRows) < self::INSERT_BATCH_SIZE) {
                continue;
            }

            $writtenReservations += count($reservationRows);
            $this->insert('reservation', self::RESERVATION_COLUMNS, $reservationRows, [0, 11, 12]);
            $this->insert('reservation_room', self::ROOM_COLUMNS, $roomRows, [0, 4]);
            $reportProgress(sprintf(
                'reservations: %d (and %d rooms in the batch)',
                $writtenReservations,
                count($roomRows),
            ));
            $reservationRows = [];
            $roomRows = [];
        }

        if ($reservationRows === []) {
            return;
        }

        $writtenReservations += count($reservationRows);
        $this->insert('reservation', self::RESERVATION_COLUMNS, $reservationRows, [0, 11, 12]);
        $this->insert('reservation_room', self::ROOM_COLUMNS, $roomRows, [0, 4]);
        $reportProgress(sprintf(
            'reservations: %d (and %d rooms in the batch)',
            $writtenReservations,
            count($roomRows),
        ));
    }

    private function pickNoteText(string|int $noteKind, ?int $noteIndex, bool $isForeignGuest): ?string
    {
        if ($noteIndex === null) {
            return null;
        }

        if ($noteKind === 'short') {
            return $isForeignGuest ? self::ENGLISH_SHORT_NOTES[$noteIndex] : self::SHORT_NOTES[$noteIndex];
        }

        return $isForeignGuest ? self::ENGLISH_LONG_NOTES[$noteIndex] : self::LONG_NOTES[$noteIndex];
    }

    /**
     * @return array<int, string|null>
     */
    private function buildGuestRow(string $id, string $name, int $sequence, string $phonePrefix): array
    {
        [$lastName, $firstName] = explode(' ', $name, 2);

        return [
            $id,
            $name,
            sprintf('%s.%s%d@example.com', $this->toAsciiSlug($firstName), $this->toAsciiSlug($lastName), $sequence),
            sprintf('%s %d', $phonePrefix, mt_rand(600000000, 799999999)),
            $this->formatCurrentTimestamp(),
            $this->formatCurrentTimestamp(),
        ];
    }

    /**
     * @return array{array<int, string|null>, bool} the row and whether the guest is foreign
     */
    private function buildRandomGuestRow(string $id, int $sequence): array
    {
        $isFemale = mt_rand(0, 1) === 1;
        $isForeign = $this->pickWeighted(self::ORIGIN_WEIGHTS) === 'foreign';

        if ($isForeign) {
            $phonePrefixesByLastName = $isFemale
                ? self::FOREIGN_FEMALE_LAST_NAME_PHONE_PREFIXES
                : self::FOREIGN_MALE_LAST_NAME_PHONE_PREFIXES;

            [$lastName, $phonePrefix] = $this->pickRandomForeignLastName($phonePrefixesByLastName);
            $firstNames = $isFemale ? self::FOREIGN_FEMALE_FIRST_NAMES : self::FOREIGN_MALE_FIRST_NAMES;
        } else {
            $rareWeights = $isFemale
                ? array_fill_keys(self::CZECH_FEMALE_RARE_LAST_NAMES, self::RARE_LAST_NAME_WEIGHT)
                : array_fill_keys(self::CZECH_MALE_RARE_LAST_NAMES, self::RARE_LAST_NAME_WEIGHT);
            $lastNameWeights = $isFemale
                ? self::CZECH_FEMALE_LAST_NAME_WEIGHTS + $rareWeights
                : self::CZECH_MALE_LAST_NAME_WEIGHTS + $rareWeights;
            $lastName = $this->pickWeighted($lastNameWeights);
            $phonePrefix = self::CZECH_PHONE_PREFIX;
            $firstNames = $isFemale ? self::CZECH_FEMALE_FIRST_NAMES : self::CZECH_MALE_FIRST_NAMES;
        }

        $guestRow = $this->buildGuestRow(
            $id,
            $lastName . ' ' . $firstNames[mt_rand(0, count($firstNames) - 1)],
            $sequence,
            $phonePrefix,
        );

        return [$guestRow, $isForeign];
    }

    /**
     * @param array<string, string> $phonePrefixesByLastName last name => phone prefix
     *
     * @return array{string, string} the last name and its phone prefix
     */
    private function pickRandomForeignLastName(array $phonePrefixesByLastName): array
    {
        $lastNames = array_keys($phonePrefixesByLastName);
        $lastName = $lastNames[mt_rand(0, count($lastNames) - 1)];

        return [$lastName, $phonePrefixesByLastName[$lastName]];
    }

    /**
     * @param array<int, string> $columns
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, int> $binaryColumns indices of BINARY(16) columns
     */
    private function insert(string $table, array $columns, array $rows, array $binaryColumns): void
    {
        if ($rows === []) {
            return;
        }

        $rowPlaceholder = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';

        foreach (array_chunk($rows, self::INSERT_BATCH_SIZE) as $chunk) {
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES %s',
                $table,
                implode(', ', $columns),
                implode(', ', array_fill(0, count($chunk), $rowPlaceholder)),
            );

            $parameters = [];
            $types = [];
            foreach ($chunk as $row) {
                foreach ($row as $columnIndex => $value) {
                    $parameters[] = $value;
                    $types[] = in_array($columnIndex, $binaryColumns, true)
                        ? ParameterType::BINARY
                        : ParameterType::STRING;
                }
            }

            $this->connection->executeStatement($sql, $parameters, $types);
        }
    }

    /**
     * @param array<TValue, int> $weights value => weight
     *
     * @return TValue
     *
     * @template TValue of array-key
     */
    private function pickWeighted(array $weights): string|int
    {
        $remainingWeight = mt_rand(1, array_sum($weights));
        foreach ($weights as $value => $weight) {
            $remainingWeight -= $weight;
            if ($remainingWeight <= 0) {
                return $value;
            }
        }

        throw new LogicException('Weights must sum to a positive number.');
    }

    private function formatCurrentTimestamp(): string
    {
        return new DateTimeImmutable()->format('Y-m-d H:i:s');
    }

    private function toAsciiSlug(string $value): string
    {
        $asciiValue = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        $letters = preg_replace('/[^a-zA-Z]/', '', $asciiValue === false ? $value : $asciiValue);

        return strtolower($letters ?? '');
    }
}
