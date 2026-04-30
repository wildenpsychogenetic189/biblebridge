<?php
/**
 * BibleBridge Reader — Core Config (Standalone)
 * Always uses API mode. Auto-detects base URL.
 */

define('BB_VERSION', '1.0.10');

// -----------------------------------------------------------
// Load local config (written by setup.php on first install)
// -----------------------------------------------------------
$_bbConfigFile    = __DIR__ . '/config.local.php';
$_bbInstalledFile = __DIR__ . '/.installed';

if (file_exists($_bbConfigFile)) {
    $bbInstall = require $_bbConfigFile;
} elseif (file_exists($_bbInstalledFile)) {
    // Was installed before but config.local.php is missing — don't silently re-provision
    http_response_code(500);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Configuration Missing</title>'
       . '<style>body{font-family:system-ui,sans-serif;max-width:520px;margin:4rem auto;padding:0 1.5rem;color:#333}'
       . 'h1{color:#b91c1c;font-size:1.3rem}code{background:#f3f4f6;padding:2px 6px;border-radius:3px;font-size:0.9em}</style></head>'
       . '<body><h1>Configuration Missing</h1>'
       . '<p>BibleBridge was previously installed, but <code>config.local.php</code> is missing.</p>'
       . '<p>To fix this, restore <code>config.local.php</code> from a backup, or re-upload the package and visit <code>/setup</code> after removing the <code>.installed</code> file.</p>'
       . '</body></html>';
    exit;
} else {
    // Fresh install — redirect to setup
    $_instDocRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '.');
    $_instPkgRoot = realpath(__DIR__);
    if ($_instDocRoot && $_instPkgRoot && str_starts_with($_instPkgRoot, $_instDocRoot)) {
        $baseUrl = rtrim(substr($_instPkgRoot, strlen($_instDocRoot)), '/');
    } else {
        $baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    }
    header('Location: ' . $baseUrl . '/setup');
    exit;
}
unset($_bbConfigFile, $_bbInstalledFile);

// Polyfill mb_* functions for hosts where mbstring is missing
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $s, ?string $enc = null): string { return strtolower($s); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $enc = null): int { return strlen($s); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $enc = null): string { return $length === null ? substr($s, $start) : substr($s, $start, $length); }
}
if (!function_exists('mb_strimwidth')) {
    function mb_strimwidth(string $s, int $start, int $width, string $trim = '', ?string $enc = null): string { return strlen($s) > $width ? substr($s, $start, $width - strlen($trim)) . $trim : $s; }
}

require_once __DIR__ . '/lib/api-client.php';

// -----------------------------------------------------------
// Base URL detection (for installations in subdirectories)
// Works regardless of which PHP file is the entry point (e.g. plans/day.php)
// -----------------------------------------------------------
$_bbDocRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '.');
$_bbPkgRoot = realpath(__DIR__);
if ($_bbDocRoot && $_bbPkgRoot && str_starts_with($_bbPkgRoot, $_bbDocRoot)) {
    $bbBaseUrl = rtrim(substr($_bbPkgRoot, strlen($_bbDocRoot)), '/');
} else {
    // Fallback: derive from SCRIPT_NAME and the relative path of this config file
    $bbBaseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    // If called from a subdirectory (e.g. plans/), strip the extra path
    $scriptFile = realpath($_SERVER['SCRIPT_FILENAME'] ?? '');
    if ($scriptFile && $_bbPkgRoot && str_starts_with($scriptFile, $_bbPkgRoot . '/')) {
        $subPath = dirname(substr($scriptFile, strlen($_bbPkgRoot)));
        if ($subPath !== '.' && $subPath !== '/') {
            $bbBaseUrl = rtrim(substr($bbBaseUrl, 0, -strlen($subPath)), '/');
        }
    }
}
unset($_bbDocRoot, $_bbPkgRoot);

// -----------------------------------------------------------
// Site identity (from install config)
// -----------------------------------------------------------
$siteDomain = $bbInstall['site_domain'];
$siteUrl    = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
              . '://' . $siteDomain;
$siteName   = $bbInstall['site_name'];

// -----------------------------------------------------------
// Bible version display names
// -----------------------------------------------------------
$version_names = $bbInstall['versions'];

// -----------------------------------------------------------
// Book data (static — same for every install)
// -----------------------------------------------------------

$books = [
    1  => 'Genesis',         2  => 'Exodus',          3  => 'Leviticus',
    4  => 'Numbers',         5  => 'Deuteronomy',      6  => 'Joshua',
    7  => 'Judges',          8  => 'Ruth',             9  => '1 Samuel',
    10 => '2 Samuel',        11 => '1 Kings',          12 => '2 Kings',
    13 => '1 Chronicles',    14 => '2 Chronicles',     15 => 'Ezra',
    16 => 'Nehemiah',        17 => 'Esther',           18 => 'Job',
    19 => 'Psalm',           20 => 'Proverbs',         21 => 'Ecclesiastes',
    22 => 'Song of Solomon', 23 => 'Isaiah',           24 => 'Jeremiah',
    25 => 'Lamentations',    26 => 'Ezekiel',          27 => 'Daniel',
    28 => 'Hosea',           29 => 'Joel',             30 => 'Amos',
    31 => 'Obadiah',         32 => 'Jonah',            33 => 'Micah',
    34 => 'Nahum',           35 => 'Habakkuk',         36 => 'Zephaniah',
    37 => 'Haggai',          38 => 'Zechariah',        39 => 'Malachi',
    40 => 'Matthew',         41 => 'Mark',             42 => 'Luke',
    43 => 'John',            44 => 'Acts',             45 => 'Romans',
    46 => '1 Corinthians',   47 => '2 Corinthians',    48 => 'Galatians',
    49 => 'Ephesians',       50 => 'Philippians',      51 => 'Colossians',
    52 => '1 Thessalonians', 53 => '2 Thessalonians',  54 => '1 Timothy',
    55 => '2 Timothy',       56 => 'Titus',            57 => 'Philemon',
    58 => 'Hebrews',         59 => 'James',            60 => '1 Peter',
    61 => '2 Peter',         62 => '1 John',           63 => '2 John',
    64 => '3 John',          65 => 'Jude',             66 => 'Revelation',
];

$max_chapters = [
    1  => 50, 2  => 40, 3  => 27, 4  => 36, 5  => 34, 6  => 24, 7  => 21,
    8  => 4,  9  => 31, 10 => 24, 11 => 22, 12 => 25, 13 => 29, 14 => 36,
    15 => 10, 16 => 13, 17 => 10, 18 => 42, 19 => 150,20 => 31, 21 => 12,
    22 => 8,  23 => 66, 24 => 52, 25 => 5,  26 => 48, 27 => 12, 28 => 14,
    29 => 3,  30 => 9,  31 => 1,  32 => 4,  33 => 7,  34 => 3,  35 => 3,
    36 => 3,  37 => 2,  38 => 14, 39 => 4,  40 => 28, 41 => 16, 42 => 24,
    43 => 21, 44 => 28, 45 => 16, 46 => 16, 47 => 13, 48 => 6,  49 => 6,
    50 => 4,  51 => 4,  52 => 5,  53 => 3,  54 => 6,  55 => 4,  56 => 3,
    57 => 1,  58 => 13, 59 => 5,  60 => 5,  61 => 3,  62 => 5,  63 => 1,
    64 => 1,  65 => 1,  66 => 22,
];

// -----------------------------------------------------------
// Helpers
// -----------------------------------------------------------

function bookToSlug(string $name): string {
    return strtolower(str_replace(' ', '-', $name));
}

function slugToBookId(string $slug, array $books): int|false {
    $slug = strtolower(trim($slug));
    foreach ($books as $id => $name) {
        if (strtolower(str_replace(' ', '-', $name)) === $slug) {
            return $id;
        }
    }
    return false;
}

// -----------------------------------------------------------
// Localized book names — native language names per translation
// -----------------------------------------------------------
$localized_books = [
    'rvr' => [ // Spanish
        1=>'Génesis',2=>'Éxodo',3=>'Levítico',4=>'Números',5=>'Deuteronomio',
        6=>'Josué',7=>'Jueces',8=>'Rut',9=>'1 Samuel',10=>'2 Samuel',
        11=>'1 Reyes',12=>'2 Reyes',13=>'1 Crónicas',14=>'2 Crónicas',15=>'Esdras',
        16=>'Nehemías',17=>'Ester',18=>'Job',19=>'Salmos',20=>'Proverbios',
        21=>'Eclesiastés',22=>'Cantares',23=>'Isaías',24=>'Jeremías',25=>'Lamentaciones',
        26=>'Ezequiel',27=>'Daniel',28=>'Oseas',29=>'Joel',30=>'Amós',
        31=>'Abdías',32=>'Jonás',33=>'Miqueas',34=>'Nahúm',35=>'Habacuc',
        36=>'Sofonías',37=>'Hageo',38=>'Zacarías',39=>'Malaquías',
        40=>'Mateo',41=>'Marcos',42=>'Lucas',43=>'Juan',44=>'Hechos',
        45=>'Romanos',46=>'1 Corintios',47=>'2 Corintios',48=>'Gálatas',
        49=>'Efesios',50=>'Filipenses',51=>'Colosenses',52=>'1 Tesalonicenses',
        53=>'2 Tesalonicenses',54=>'1 Timoteo',55=>'2 Timoteo',56=>'Tito',
        57=>'Filemón',58=>'Hebreos',59=>'Santiago',60=>'1 Pedro',
        61=>'2 Pedro',62=>'1 Juan',63=>'2 Juan',64=>'3 Juan',65=>'Judas',66=>'Apocalipsis',
    ],
    'lsg' => [ // French
        1=>'Genèse',2=>'Exode',3=>'Lévitique',4=>'Nombres',5=>'Deutéronome',
        6=>'Josué',7=>'Juges',8=>'Ruth',9=>'1 Samuel',10=>'2 Samuel',
        11=>'1 Rois',12=>'2 Rois',13=>'1 Chroniques',14=>'2 Chroniques',15=>'Esdras',
        16=>'Néhémie',17=>'Esther',18=>'Job',19=>'Psaumes',20=>'Proverbes',
        21=>'Ecclésiaste',22=>'Cantique des Cantiques',23=>'Ésaïe',24=>'Jérémie',25=>'Lamentations',
        26=>'Ézéchiel',27=>'Daniel',28=>'Osée',29=>'Joël',30=>'Amos',
        31=>'Abdias',32=>'Jonas',33=>'Michée',34=>'Nahum',35=>'Habacuc',
        36=>'Sophonie',37=>'Aggée',38=>'Zacharie',39=>'Malachie',
        40=>'Matthieu',41=>'Marc',42=>'Luc',43=>'Jean',44=>'Actes',
        45=>'Romains',46=>'1 Corinthiens',47=>'2 Corinthiens',48=>'Galates',
        49=>'Éphésiens',50=>'Philippiens',51=>'Colossiens',52=>'1 Thessaloniciens',
        53=>'2 Thessaloniciens',54=>'1 Timothée',55=>'2 Timothée',56=>'Tite',
        57=>'Philémon',58=>'Hébreux',59=>'Jacques',60=>'1 Pierre',
        61=>'2 Pierre',62=>'1 Jean',63=>'2 Jean',64=>'3 Jean',65=>'Jude',66=>'Apocalypse',
    ],
    'lut' => [ // German
        1=>'1. Mose',2=>'2. Mose',3=>'3. Mose',4=>'4. Mose',5=>'5. Mose',
        6=>'Josua',7=>'Richter',8=>'Rut',9=>'1. Samuel',10=>'2. Samuel',
        11=>'1. Könige',12=>'2. Könige',13=>'1. Chronik',14=>'2. Chronik',15=>'Esra',
        16=>'Nehemia',17=>'Ester',18=>'Hiob',19=>'Psalmen',20=>'Sprüche',
        21=>'Prediger',22=>'Hohelied',23=>'Jesaja',24=>'Jeremia',25=>'Klagelieder',
        26=>'Hesekiel',27=>'Daniel',28=>'Hosea',29=>'Joel',30=>'Amos',
        31=>'Obadja',32=>'Jona',33=>'Micha',34=>'Nahum',35=>'Habakuk',
        36=>'Zefanja',37=>'Haggai',38=>'Sacharja',39=>'Maleachi',
        40=>'Matthäus',41=>'Markus',42=>'Lukas',43=>'Johannes',44=>'Apostelgeschichte',
        45=>'Römer',46=>'1. Korinther',47=>'2. Korinther',48=>'Galater',
        49=>'Epheser',50=>'Philipper',51=>'Kolosser',52=>'1. Thessalonicher',
        53=>'2. Thessalonicher',54=>'1. Timotheus',55=>'2. Timotheus',56=>'Titus',
        57=>'Philemon',58=>'Hebräer',59=>'Jakobus',60=>'1. Petrus',
        61=>'2. Petrus',62=>'1. Johannes',63=>'2. Johannes',64=>'3. Johannes',65=>'Judas',66=>'Offenbarung',
    ],
    'ara' => [ // Portuguese
        1=>'Gênesis',2=>'Êxodo',3=>'Levítico',4=>'Números',5=>'Deuteronômio',
        6=>'Josué',7=>'Juízes',8=>'Rute',9=>'1 Samuel',10=>'2 Samuel',
        11=>'1 Reis',12=>'2 Reis',13=>'1 Crônicas',14=>'2 Crônicas',15=>'Esdras',
        16=>'Neemias',17=>'Ester',18=>'Jó',19=>'Salmos',20=>'Provérbios',
        21=>'Eclesiastes',22=>'Cânticos',23=>'Isaías',24=>'Jeremias',25=>'Lamentações',
        26=>'Ezequiel',27=>'Daniel',28=>'Oséias',29=>'Joel',30=>'Amós',
        31=>'Obadias',32=>'Jonas',33=>'Miquéias',34=>'Naum',35=>'Habacuque',
        36=>'Sofonias',37=>'Ageu',38=>'Zacarias',39=>'Malaquias',
        40=>'Mateus',41=>'Marcos',42=>'Lucas',43=>'João',44=>'Atos',
        45=>'Romanos',46=>'1 Coríntios',47=>'2 Coríntios',48=>'Gálatas',
        49=>'Efésios',50=>'Filipenses',51=>'Colossenses',52=>'1 Tessalonicenses',
        53=>'2 Tessalonicenses',54=>'1 Timóteo',55=>'2 Timóteo',56=>'Tito',
        57=>'Filemom',58=>'Hebreus',59=>'Tiago',60=>'1 Pedro',
        61=>'2 Pedro',62=>'1 João',63=>'2 João',64=>'3 João',65=>'Judas',66=>'Apocalipse',
    ],
    'cuv' => [ // Chinese
        1=>'创世记',2=>'出埃及记',3=>'利未记',4=>'民数记',5=>'申命记',
        6=>'约书亚记',7=>'士师记',8=>'路得记',9=>'撒母耳记上',10=>'撒母耳记下',
        11=>'列王纪上',12=>'列王纪下',13=>'历代志上',14=>'历代志下',15=>'以斯拉记',
        16=>'尼希米记',17=>'以斯帖记',18=>'约伯记',19=>'诗篇',20=>'箴言',
        21=>'传道书',22=>'雅歌',23=>'以赛亚书',24=>'耶利米书',25=>'耶利米哀歌',
        26=>'以西结书',27=>'但以理书',28=>'何西阿书',29=>'约珥书',30=>'阿摩司书',
        31=>'俄巴底亚书',32=>'约拿书',33=>'弥迦书',34=>'那鸿书',35=>'哈巴谷书',
        36=>'西番雅书',37=>'哈该书',38=>'撒迦利亚书',39=>'玛拉基书',
        40=>'马太福音',41=>'马可福音',42=>'路加福音',43=>'约翰福音',44=>'使徒行传',
        45=>'罗马书',46=>'哥林多前书',47=>'哥林多后书',48=>'加拉太书',
        49=>'以弗所书',50=>'腓立比书',51=>'歌罗西书',52=>'帖撒罗尼迦前书',
        53=>'帖撒罗尼迦后书',54=>'提摩太前书',55=>'提摩太后书',56=>'提多书',
        57=>'腓利门书',58=>'希伯来书',59=>'雅各书',60=>'彼得前书',
        61=>'彼得后书',62=>'约翰一书',63=>'约翰二书',64=>'约翰三书',65=>'犹大书',66=>'启示录',
    ],
    'krv' => [ // Korean
        1=>'창세기',2=>'출애굽기',3=>'레위기',4=>'민수기',5=>'신명기',
        6=>'여호수아',7=>'사사기',8=>'룻기',9=>'사무엘상',10=>'사무엘하',
        11=>'열왕기상',12=>'열왕기하',13=>'역대상',14=>'역대하',15=>'에스라',
        16=>'느헤미야',17=>'에스더',18=>'욥기',19=>'시편',20=>'잠언',
        21=>'전도서',22=>'아가',23=>'이사야',24=>'예레미야',25=>'예레미야애가',
        26=>'에스겔',27=>'다니엘',28=>'호세아',29=>'요엘',30=>'아모스',
        31=>'오바댜',32=>'요나',33=>'미가',34=>'나훔',35=>'하박국',
        36=>'스바냐',37=>'학개',38=>'스가랴',39=>'말라기',
        40=>'마태복음',41=>'마가복음',42=>'누가복음',43=>'요한복음',44=>'사도행전',
        45=>'로마서',46=>'고린도전서',47=>'고린도후서',48=>'갈라디아서',
        49=>'에베소서',50=>'빌립보서',51=>'골로새서',52=>'데살로니가전서',
        53=>'데살로니가후서',54=>'디모데전서',55=>'디모데후서',56=>'디도서',
        57=>'빌레몬서',58=>'히브리서',59=>'야고보서',60=>'베드로전서',
        61=>'베드로후서',62=>'요한일서',63=>'요한이서',64=>'요한삼서',65=>'유다서',66=>'요한계시록',
    ],
    'adb' => [ // Tagalog
        1=>'Genesis',2=>'Exodo',3=>'Levitico',4=>'Mga Bilang',5=>'Deuteronomio',
        6=>'Josue',7=>'Mga Hukom',8=>'Ruth',9=>'1 Samuel',10=>'2 Samuel',
        11=>'1 Mga Hari',12=>'2 Mga Hari',13=>'1 Mga Cronica',14=>'2 Mga Cronica',
        15=>'Ezra',16=>'Nehemias',17=>'Esther',18=>'Job',19=>'Mga Awit',
        20=>'Mga Kawikaan',21=>'Mangangaral',22=>'Awit ni Solomon',23=>'Isaias',
        24=>'Jeremias',25=>'Mga Panaghoy',26=>'Ezekiel',27=>'Daniel',28=>'Oseas',
        29=>'Joel',30=>'Amos',31=>'Obadias',32=>'Jonas',33=>'Mikas',34=>'Nahum',
        35=>'Habakuk',36=>'Zefanias',37=>'Haggeo',38=>'Zacarias',39=>'Malakias',
        40=>'Mateo',41=>'Marcos',42=>'Lucas',43=>'Juan',44=>'Mga Gawa',
        45=>'Mga Taga-Roma',46=>'1 Mga Taga-Corinto',47=>'2 Mga Taga-Corinto',
        48=>'Mga Taga-Galacia',49=>'Mga Taga-Efeso',50=>'Mga Taga-Filipos',
        51=>'Mga Taga-Colosas',52=>'1 Mga Taga-Tesalonica',53=>'2 Mga Taga-Tesalonica',
        54=>'1 Timoteo',55=>'2 Timoteo',56=>'Tito',57=>'Filemon',58=>'Mga Hebreo',
        59=>'Santiago',60=>'1 Pedro',61=>'2 Pedro',62=>'1 Juan',63=>'2 Juan',
        64=>'3 Juan',65=>'Judas',66=>'Apocalipsis',
    ],
];

// Passage nicknames (e.g. "beatitudes" -> Matthew 5:3-12)
require_once __DIR__ . '/lib/passage_nicknames.php';

/**
 * Resolve a slug to book ID, checking both English and localized names.
 */
// Common alternate slugs people type
$BOOK_ALIASES = [
    'psalms' => 19, 'proverbs' => 20, 'songs' => 22, 'song-of-songs' => 22,
    'revelations' => 66, 'rev' => 66, 'apoc' => 66,
    '1-sam' => 9, '2-sam' => 10, '1-kin' => 11, '2-kin' => 12,
    '1-chr' => 13, '2-chr' => 14, '1-cor' => 46, '2-cor' => 47,
    '1-thess' => 52, '2-thess' => 53, '1-tim' => 54, '2-tim' => 55,
    '1-pet' => 60, '2-pet' => 61, '1-jn' => 62, '2-jn' => 63, '3-jn' => 64,
    'jn' => 43, 'mk' => 41, 'mt' => 40, 'lk' => 42, 'gen' => 1, 'ex' => 2,
    'lev' => 3, 'num' => 4, 'deut' => 5, 'ps' => 19, 'prov' => 20,
    'isa' => 23, 'jer' => 24, 'lam' => 25, 'ezek' => 26, 'dan' => 27,
    'hos' => 28, 'am' => 30, 'mic' => 33, 'hab' => 35, 'zeph' => 36,
    'hag' => 37, 'zech' => 38, 'mal' => 39,
    'rom' => 45, 'gal' => 48, 'eph' => 49, 'phil' => 50, 'col' => 51,
    'heb' => 58, 'jas' => 59,
];

function slugToBookIdMulti(string $slug, array $books, array $localized_books): int|false {
    global $BOOK_ALIASES;
    $slug = mb_strtolower(trim($slug));
    // Alias check first
    if (isset($BOOK_ALIASES[$slug])) return $BOOK_ALIASES[$slug];
    // Exact match
    foreach ($books as $id => $name) {
        if (mb_strtolower(str_replace(' ', '-', $name)) === $slug) return $id;
    }
    foreach ($localized_books as $ver => $localBooks) {
        foreach ($localBooks as $bid => $name) {
            if (mb_strtolower(str_replace(' ', '-', $name)) === $slug) return $bid;
        }
    }
    // Prefix match -- shortest matching name wins (most specific)
    if (mb_strlen($slug) >= 2) {
        $bestId = false;
        $bestLen = 9999;
        // English
        foreach ($books as $id => $name) {
            $lower = mb_strtolower($name);
            if (str_starts_with($lower, $slug) && mb_strlen($lower) < $bestLen) {
                $bestId = $id;
                $bestLen = mb_strlen($lower);
            }
        }
        // Localized -- prefer shorter (more specific) matches
        foreach ($localized_books as $ver => $localBooks) {
            foreach ($localBooks as $bid => $name) {
                $lower = mb_strtolower($name);
                if (str_starts_with($lower, $slug) && mb_strlen($lower) < $bestLen) {
                    $bestId = $bid;
                    $bestLen = mb_strlen($lower);
                }
            }
        }
        if ($bestId !== false) return $bestId;
    }
    return false;
}
