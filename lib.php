<?php
declare(strict_types=1);

require __DIR__ . '/config.php';
require __DIR__ . '/version.php';

date_default_timezone_set('Europe/Zurich');
session_name('flaggenquiz');
session_set_cookie_params([
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'),
]);
session_start();

require __DIR__ . '/inc/einstellungen.php';
require __DIR__ . '/inc/mail.php';
require __DIR__ . '/inc/avatar.php';
require __DIR__ . '/inc/profil.php';
require __DIR__ . '/inc/einmaleins.php';

/*
 * ---- Sprache ----
 * Deutsch ist die Grundsprache: alle Texte stehen deutsch im Code und werden mit t()
 * übersetzt. Die Übersetzungen und die Länder-/Hauptstadtnamen einer weiteren Sprache
 * stehen in lang/<code>.php.
 */
const SPRACHEN = ['de' => 'Deutsch', 'fr' => 'Français'];

const APP_NAME = 'BrainForge';
const APP_REPO = 'https://github.com/laudatioUno/BrainForge';

/**
 * Aktuelle Sprache: ?lang=… (wird gemerkt) › Cookie › Browser-Sprache › STANDARD_SPRACHE
 * Mit $setzen lässt sie sich fest einstellen (z. B. im Selbsttest).
 */
function sprache(?string $setzen = null): string
{
    static $sprache = null;
    if ($setzen !== null && isset(SPRACHEN[$setzen])) {
        return $sprache = $setzen;
    }
    if ($sprache !== null) {
        return $sprache;
    }
    $wahl = $_GET['lang'] ?? null;
    if (is_string($wahl) && isset(SPRACHEN[$wahl])) {
        if (!headers_sent()) {
            setcookie('sprache', $wahl, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
        }
        return $sprache = $wahl;
    }
    $cookie = $_COOKIE['sprache'] ?? null;
    if (is_string($cookie) && isset(SPRACHEN[$cookie])) {
        return $sprache = $cookie;
    }
    // Browser-Sprache, z. B. "fr-CH,fr;q=0.9,de;q=0.8"
    foreach (explode(',', (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $teil) {
        $code = strtolower(substr(trim($teil), 0, 2));
        if (isset(SPRACHEN[$code])) {
            return $sprache = $code;
        }
    }
    $standard = defined('STANDARD_SPRACHE') ? STANDARD_SPRACHE : 'de';
    return $sprache = isset(SPRACHEN[$standard]) ? $standard : 'de';
}

/** Inhalt von lang/<sprache>.php (leer für Deutsch). */
function sprachpaket(?string $sprache = null): array
{
    static $pakete = [];
    $sprache = $sprache ?? sprache();
    if ($sprache === 'de') {
        return [];
    }
    return $pakete[$sprache] ??= require __DIR__ . '/lang/' . $sprache . '.php';
}

/**
 * Übersetzt einen deutschen Text in die aktuelle Sprache.
 * Platzhalter wie {n} werden durch $werte['n'] ersetzt.
 */
function t(string $text, array $werte = []): string
{
    $uebersetzt = sprachpaket()['texte'][$text] ?? $text;
    foreach ($werte as $k => $v) {
        $uebersetzt = str_replace('{' . $k . '}', (string)$v, $uebersetzt);
    }
    return $uebersetzt;
}

/** Länder- und Hauptstadtnamen der aktuellen Sprache (für Anzeige und Antwortprüfung). */
function namen(?string $sprache = null): array
{
    return ($sprache ?? sprache()) === 'de' ? LAENDER : sprachpaket($sprache)['laender'];
}

/** Ungenaue, aber zugelassene Antworten der aktuellen Sprache. */
function toleriert(?string $sprache = null): array
{
    return ($sprache ?? sprache()) === 'de' ? TOLERIERT : (sprachpaket($sprache)['toleriert'] ?? []);
}

/** Link auf die aktuelle Seite mit geänderten/zusätzlichen Parametern. */
function link_hier(array $param): string
{
    $pfad = strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: 'index.php';
    $query = array_merge($_GET, $param);
    return basename($pfad) . '?' . http_build_query($query);
}

/*
 * ---- Darstellung (hell/dunkel) ----
 * 'auto' folgt der Einstellung des Geräts, 'hell' und 'dunkel' legen sie fest.
 */
const THEMEN = ['auto' => 'Automatisch', 'hell' => 'Hell', 'dunkel' => 'Dunkel'];

/** Gewählte Darstellung: ?thema=… (wird gemerkt) › Cookie › 'auto' */
function thema(): string
{
    static $thema = null;
    if ($thema !== null) {
        return $thema;
    }
    $wahl = $_GET['thema'] ?? null;
    if (is_string($wahl) && isset(THEMEN[$wahl])) {
        if (!headers_sent()) {
            setcookie('thema', $wahl, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
        }
        return $thema = $wahl;
    }
    $cookie = $_COOKIE['thema'] ?? null;
    return $thema = (is_string($cookie) && isset(THEMEN[$cookie])) ? $cookie : 'auto';
}

/** Umschalter Automatisch / Hell / Dunkel (Links mit Symbol). */
function themawahl(): string
{
    $symbol = [
        'auto' => '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8 2a6 6 0 0 1 0 12z" fill="currentColor"/></svg>',
        'hell' => '<svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="8" cy="8" r="3" fill="currentColor"/><path d="M8 1v2M8 13v2M1 8h2M13 8h2M3 3l1.4 1.4M11.6 11.6 13 13M3 13l1.4-1.4M11.6 4.4 13 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>',
        'dunkel' => '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M13.5 10.2A6 6 0 0 1 5.8 2.5a6 6 0 1 0 7.7 7.7z" fill="currentColor"/></svg>',
    ];
    $html = '<nav class="themawahl" aria-label="' . h(t('Darstellung')) . '">';
    foreach (THEMEN as $code => $name) {
        $inhalt = $symbol[$code] . '<span class="thema-name">' . h(t($name)) . '</span>';
        $html .= $code === thema()
            ? '<span aria-current="true">' . $inhalt . '</span>'
            : '<a href="' . h(link_hier(['thema' => $code])) . '">' . $inhalt . '</a>';
    }
    return $html . '</nav>';
}

/** Sprach- und Darstellungsumschalter zusammen. */
function umschalter(): string
{
    return '<div class="umschalter-leiste">' . sprachwahl() . themawahl() . '</div>';
}

/** Sprachumschalter (Links). */
function sprachwahl(): string
{
    $html = '<nav class="sprachwahl" aria-label="' . h(t('Sprache')) . '">';
    foreach (SPRACHEN as $code => $name) {
        $html .= $code === sprache()
            ? '<span aria-current="true" lang="' . $code . '">' . h($name) . '</span>'
            : '<a href="' . h(link_hier(['lang' => $code])) . '" lang="' . $code . '" hreflang="' . $code . '">' . h($name) . '</a>';
    }
    return $html . '</nav>';
}

/**
 * Länderdaten: Code => [Land (offiziell angezeigt), Hauptstadt, [erlaubte Ländernamen], [erlaubte Hauptstädte]]
 * Der erste Eintrag jeder Liste ist die Musterlösung. Umlaute, Akzente,
 * Bindestriche und Gross-/Kleinschreibung werden beim Vergleich ignoriert.
 */
const LAENDER = [
    'al' => [['Albanien'], ['Tirana', 'Tirane']],
    'ad' => [['Andorra'], ['Andorra la Vella']],
    'be' => [['Belgien'], ['Brüssel', 'Bruxelles', 'Brussel']],
    'by' => [['Belarus', 'Weissrussland', 'Belarussland'], ['Minsk']],
    'ba' => [['Bosnien und Herzegowina', 'Bosnien-Herzegowina', 'Bosnien und Herzegovina'], ['Sarajevo']],
    'bg' => [['Bulgarien'], ['Sofia']],
    'dk' => [['Dänemark'], ['Kopenhagen']],
    'de' => [['Deutschland'], ['Berlin']],
    'ee' => [['Estland'], ['Tallinn']],
    'fi' => [['Finnland'], ['Helsinki']],
    'fr' => [['Frankreich'], ['Paris']],
    'gr' => [['Griechenland'], ['Athen']],
    'ie' => [['Irland'], ['Dublin']],
    'is' => [['Island'], ['Reykjavík']],
    'it' => [['Italien'], ['Rom']],
    'xk' => [['Kosovo'], ['Pristina', 'Prishtina']],
    'hr' => [['Kroatien'], ['Zagreb']],
    'lv' => [['Lettland'], ['Riga']],
    'li' => [['Liechtenstein'], ['Vaduz']],
    'lt' => [['Litauen'], ['Vilnius']],
    'lu' => [['Luxemburg'], ['Luxemburg', 'Luxembourg']],
    'mt' => [['Malta'], ['Valletta']],
    'md' => [['Moldau', 'Moldawien', 'Republik Moldau', 'Moldova'], ['Chișinău', 'Kischinau']],
    'mc' => [['Monaco'], ['Monaco']],
    'me' => [['Montenegro'], ['Podgorica']],
    'nl' => [['Niederlande'], ['Amsterdam']],
    'mk' => [['Nordmazedonien'], ['Skopje']],
    'no' => [['Norwegen'], ['Oslo']],
    'at' => [['Österreich'], ['Wien']],
    'pl' => [['Polen'], ['Warschau']],
    'pt' => [['Portugal'], ['Lissabon']],
    'ro' => [['Rumänien'], ['Bukarest']],
    'ru' => [['Russland'], ['Moskau']],
    'sm' => [['San Marino'], ['San Marino']],
    'se' => [['Schweden'], ['Stockholm']],
    'ch' => [['Schweiz'], ['Bern']],
    'rs' => [['Serbien'], ['Belgrad', 'Beograd']],
    'sk' => [['Slowakei'], ['Bratislava']],
    'si' => [['Slowenien'], ['Ljubljana']],
    'es' => [['Spanien'], ['Madrid']],
    'cz' => [['Tschechien', 'Tschechische Republik'], ['Prag', 'Praha']],
    'tr' => [['Türkei'], ['Ankara']],
    'ua' => [['Ukraine'], ['Kiew', 'Kyjiw', 'Kiev', 'Kyiv']],
    'hu' => [['Ungarn'], ['Budapest']],
    'va' => [['Vatikanstadt', 'Vatikan', 'Vatikanstaat', 'Staat der Vatikanstadt'], ['Vatikanstadt', 'Vatikan']],
    'gb' => [['Vereinigtes Königreich', 'Grossbritannien', 'Vereinigtes Königreich Grossbritannien und Nordirland', 'Grossbritannien und Nordirland', 'United Kingdom', 'UK'], ['London']],
];

/** Neutrale Dateinamen der Flaggen (damit der Dateiname das Land nicht verrät). */
const FLAGGEN_DATEI = array (
  'al' => '061d73cc871b',
  'ad' => '315a8ed0d3d0',
  'be' => '4f69e910b72d',
  'by' => 'd253c017e9dc',
  'ba' => '160002702766',
  'bg' => '5d4928242560',
  'dk' => 'ff4d2a0a9fc1',
  'de' => 'db64cbb1a476',
  'ee' => '2af88b8b14a9',
  'fi' => 'e05014b11f54',
  'fr' => 'd551ad86a827',
  'gr' => 'e3f5a09f9e34',
  'ie' => '6f2b14928ee1',
  'is' => '01b6ed3fde8f',
  'it' => '1bdfb25a208b',
  'xk' => '6edb02e822ef',
  'hr' => '4309db138261',
  'lv' => '75afd2b13a64',
  'li' => 'b10b7565fc44',
  'lt' => '50f6a5da8c46',
  'lu' => '2f1c68b4c824',
  'mt' => 'bd97237b1779',
  'md' => 'f3f08b835389',
  'mc' => '6965085df15c',
  'me' => 'f97e6ac5461b',
  'nl' => '04cbeb750ebd',
  'mk' => '7e5b17204b3e',
  'no' => '0c027332bb8d',
  'at' => '9e46002a6d42',
  'pl' => '7f4da8bb907d',
  'pt' => '379690740996',
  'ro' => '69a624b76802',
  'ru' => '45aa8bfde155',
  'sm' => '59f29e8c1743',
  'se' => '02dd8b289349',
  'ch' => 'f553e6b0287b',
  'rs' => '40bba8c89276',
  'sk' => '35619822db7a',
  'si' => '41759ba4d8cf',
  'es' => 'd6e0ad0fc17b',
  'cz' => '2ce518383324',
  'tr' => '02ddab84e1b9',
  'ua' => '289ebc49ec45',
  'hu' => '3cb7a24ed78b',
  'va' => 'd6f199e46780',
  'gb' => 'cfa8837d1daf',
);

function flagge(string $code): string
{
    return "flags/" . FLAGGEN_DATEI[$code] . ".svg";
}

/** Neutrale Dateinamen der Umrisse bzw. Kartenausschnitte (Ordner umrisse/). */
const UMRISS_DATEI = [
    'al' => '717458601b45', 'ad' => 'a58d7ca155bb', 'be' => '5574e877d66d', 'by' => 'd08fc546aa9c',
    'ba' => '2da44995f44b', 'bg' => '6d82aea94611', 'dk' => '1df300f096a1', 'de' => '7964ee27ee54',
    'ee' => 'd87ae8bc0f4c', 'fi' => '0c246e6d6701', 'fr' => 'a4af2776c0f9', 'gr' => 'ca55bdb02c7c',
    'ie' => '26bf72ed85af', 'is' => 'd320b3f36ea3', 'it' => '07d7a5af29a8', 'xk' => '8bf06c12cf48',
    'hr' => 'ef40376dc3a3', 'lv' => 'ce3d6cbbaca8', 'li' => '41fa850aaf40', 'lt' => '35cc9fa07e15',
    'lu' => 'ad32555b0f96', 'mt' => '4a34bf4bf069', 'md' => '5ddf10258054', 'mc' => '65b3196e41a4',
    'me' => '7e810deef6b6', 'nl' => 'a77146e2c3d3', 'mk' => '1a18ced149e8', 'no' => 'b6f95bc9c8d5',
    'at' => '817568c482c4', 'pl' => 'a43b81abdaaf', 'pt' => '679d4da9d16a', 'ro' => 'f0dd65072688',
    'ru' => 'b79cf16e6bd1', 'sm' => '158459ee54b7', 'se' => '216b53d86f00', 'ch' => 'b474f01f7b84',
    'rs' => 'c5b061f106c3', 'sk' => 'b10393f73e29', 'si' => 'a4ccb054bb4c', 'es' => 'c115247c1f48',
    'cz' => '005820203868', 'tr' => '7f29d4fa63f6', 'ua' => 'fd706ad2c44d', 'hu' => '18b3432fa8fa',
    'va' => 'ae907556d517', 'gb' => 'a0d44ebdb533',
];

function umriss(string $code): string
{
    return 'umrisse/' . UMRISS_DATEI[$code] . '.svg';
}

/** Als Umriss zu klein – diese werden als Kartenausschnitt mit roter Markierung gezeigt. */
const KLEINSTAATEN = ['ad', 'li', 'mc', 'sm', 'va'];

/**
 * Plausible falsche Antworten für die Stufe "Leicht" (Ländernamen):
 * Nachbarländer bzw. Länder derselben Region. Kleinstaaten untereinander.
 */
const AEHNLICH_LAND = [
    'al' => ['me', 'mk', 'gr', 'xk', 'rs', 'ba'],
    'ad' => ['li', 'mc', 'sm', 'va', 'lu', 'mt'],
    'be' => ['nl', 'lu', 'fr', 'de', 'ch', 'at'],
    'by' => ['ua', 'pl', 'lt', 'lv', 'ru', 'md'],
    'ba' => ['hr', 'rs', 'me', 'si', 'mk', 'al'],
    'bg' => ['ro', 'rs', 'mk', 'gr', 'tr', 'hu'],
    'dk' => ['de', 'nl', 'se', 'no', 'ee', 'is'],
    'de' => ['at', 'pl', 'cz', 'nl', 'dk', 'fr', 'ch'],
    'ee' => ['lv', 'lt', 'fi', 'by', 'dk'],
    'fi' => ['se', 'no', 'ee', 'lv', 'is'],
    'fr' => ['es', 'be', 'de', 'ch', 'it', 'pt'],
    'gr' => ['al', 'mk', 'bg', 'tr', 'it', 'hr'],
    'ie' => ['gb', 'is', 'dk', 'pt', 'be'],
    'is' => ['ie', 'no', 'dk', 'fi', 'gb', 'mt'],
    'it' => ['gr', 'hr', 'pt', 'es', 'si', 'ch'],
    'xk' => ['rs', 'al', 'mk', 'me', 'ba'],
    'hr' => ['si', 'ba', 'rs', 'me', 'hu', 'sk'],
    'lv' => ['lt', 'ee', 'by', 'pl', 'dk'],
    'li' => ['ad', 'mc', 'sm', 'va', 'lu'],
    'lt' => ['lv', 'ee', 'by', 'pl', 'ua'],
    'lu' => ['be', 'nl', 'ch', 'li', 'de'],
    'mt' => ['it', 'gr', 'ie', 'is', 'pt'],
    'md' => ['ro', 'ua', 'by', 'hu', 'bg'],
    'mc' => ['ad', 'sm', 'li', 'va', 'mt'],
    'me' => ['al', 'ba', 'rs', 'xk', 'mk', 'hr'],
    'nl' => ['be', 'lu', 'de', 'dk', 'at'],
    'mk' => ['al', 'xk', 'bg', 'rs', 'gr', 'me'],
    'no' => ['se', 'fi', 'dk', 'is', 'ee'],
    'at' => ['ch', 'de', 'hu', 'cz', 'sk', 'si'],
    'pl' => ['cz', 'sk', 'de', 'by', 'ua', 'lt'],
    'pt' => ['es', 'it', 'gr', 'ie', 'fr'],
    'ro' => ['md', 'bg', 'hu', 'ua', 'rs'],
    'ru' => ['ua', 'by', 'fi', 'no', 'tr', 'se'],
    'sm' => ['va', 'mc', 'ad', 'li', 'mt'],
    'se' => ['no', 'fi', 'dk', 'ee', 'is'],
    'ch' => ['at', 'li', 'de', 'fr', 'it', 'cz'],
    'rs' => ['hr', 'ba', 'me', 'xk', 'mk', 'bg', 'hu'],
    'sk' => ['cz', 'at', 'hu', 'pl', 'si', 'ua'],
    'si' => ['hr', 'at', 'sk', 'hu', 'ch', 'it'],
    'es' => ['pt', 'fr', 'it', 'ad', 'gr'],
    'cz' => ['sk', 'at', 'pl', 'de', 'hu', 'si'],
    'tr' => ['gr', 'bg', 'ru', 'ua', 'it'],
    'ua' => ['by', 'pl', 'md', 'ro', 'ru', 'sk'],
    'hu' => ['at', 'sk', 'ro', 'rs', 'cz', 'si'],
    'va' => ['sm', 'mc', 'ad', 'li', 'mt'],
    'gb' => ['ie', 'is', 'no', 'dk', 'nl'],
];

/** Plausible falsche Antworten für die Stufe "Mittel" (ähnlich aussehende Flaggen). */
const AEHNLICH_FLAGGE = [
    'al' => ['me', 'mk', 'tr', 'ch', 'by'],
    'ad' => ['ro', 'md', 'be', 'es', 'fr'],
    'be' => ['de', 'fr', 'ro', 'it', 'ie', 'ad'],
    'by' => ['pt', 'lt', 'bg', 'hu', 'md'],
    'ba' => ['ua', 'xk', 'se', 'cz', 'sk'],
    'bg' => ['hu', 'it', 'lt', 'ru', 'ie'],
    'dk' => ['ch', 'no', 'is', 'mt', 'gb'],
    'de' => ['be', 'lt', 'es', 'at', 'ee'],
    'ee' => ['fi', 'lv', 'de', 'nl', 'lt'],
    'fi' => ['se', 'is', 'no', 'ee', 'gr'],
    'fr' => ['it', 'ie', 'be', 'nl', 'ro'],
    'gr' => ['fi', 'is', 'xk', 'sm', 'se'],
    'ie' => ['it', 'fr', 'bg', 'hu', 'be'],
    'is' => ['no', 'fi', 'dk', 'se', 'gb'],
    'it' => ['ie', 'fr', 'hu', 'bg', 'be'],
    'xk' => ['ba', 'ua', 'sm', 'se', 'gr'],
    'hr' => ['sk', 'si', 'rs', 'ru', 'nl'],
    'lv' => ['at', 'pl', 'mc', 'li', 'dk'],
    'li' => ['ad', 'mc', 'sm', 'lv', 'ru'],
    'lt' => ['bg', 'ee', 'de', 'hu', 'by'],
    'lu' => ['nl', 'ru', 'fr', 'hr', 'sk'],
    'mt' => ['pl', 'mc', 'ch', 'dk', 'at'],
    'md' => ['ro', 'ad', 'be', 'fr', 'es'],
    'mc' => ['pl', 'at', 'lv', 'mt', 'li'],
    'me' => ['al', 'mk', 'es', 'tr', 'rs'],
    'nl' => ['lu', 'ru', 'fr', 'hr', 'sk'],
    'mk' => ['es', 'me', 'al', 'tr', 'md'],
    'no' => ['is', 'dk', 'fi', 'se', 'gb'],
    'at' => ['lv', 'pl', 'mc', 'dk', 'ch'],
    'pl' => ['mc', 'at', 'lv', 'mt', 'li'],
    'pt' => ['by', 'es', 'it', 'ad', 'bg'],
    'ro' => ['md', 'ad', 'be', 'fr', 'it'],
    'ru' => ['nl', 'sk', 'si', 'rs', 'lu', 'hr'],
    'sm' => ['va', 'gr', 'xk', 'li', 'ad'],
    'se' => ['fi', 'no', 'is', 'dk', 'ua'],
    'ch' => ['dk', 'at', 'mt', 'gb', 'al'],
    'rs' => ['ru', 'sk', 'si', 'hr', 'nl'],
    'sk' => ['si', 'ru', 'hr', 'cz', 'rs'],
    'si' => ['sk', 'ru', 'hr', 'rs', 'cz'],
    'es' => ['ad', 'mk', 'md', 'de', 'pt'],
    'cz' => ['sk', 'ru', 'ba', 'si', 'nl'],
    'tr' => ['al', 'ch', 'me', 'mk', 'dk'],
    'ua' => ['se', 'ba', 'xk', 'ro', 'lt'],
    'hu' => ['it', 'bg', 'ie', 'lt', 'at'],
    'va' => ['sm', 'ad', 'mc', 'es', 'li'],
    'gb' => ['no', 'is', 'ch', 'dk', 'fi'],
];

/**
 * Stellt 4 Antwortmöglichkeiten (1 richtige + 3 falsche) zusammen, gemischt.
 * Damit nicht jedes Mal dieselben Möglichkeiten kommen:
 * - 2 falsche zufällig aus der Liste der ähnlichen Länder/Flaggen,
 * - die dritte je zur Hälfte aus dieser Liste oder aus allen übrigen Ländern,
 * - die Reihenfolge wird gemischt,
 * - ergibt sich zufällig genau dieselbe Auswahl wie beim letzten Mal
 *   (gleiche Sitzung), wird neu gezogen.
 */
function antwortmoeglichkeiten(string $code, string $stufe): array
{
    $quelle = $stufe === 'mittel' ? AEHNLICH_FLAGGE : AEHNLICH_LAND;
    $karte = $stufe !== 'mittel' && in_array($code, KLEINSTAATEN, true);
    $letzte = $_SESSION['letzte_optionen'][$stufe][$code] ?? null;

    for ($versuch = 0; $versuch < 8; $versuch++) {
        $pool = array_values(array_diff(array_unique($quelle[$code] ?? []), [$code]));
        shuffle($pool);
        $falsch = array_slice($pool, 0, 2);
        $rest = $karte
            // Kleinstaaten auf der Karte: nur mit anderen kleinen Staaten vergleichen
            ? array_values(array_diff(array_merge(KLEINSTAATEN, ['lu', 'mt']), [$code], $falsch))
            : array_values(array_diff(array_keys(LAENDER), [$code], $falsch));
        $falsch[] = (isset($pool[2]) && random_int(0, 1) === 0) ? $pool[2] : $rest[random_int(0, count($rest) - 1)];

        $menge = $falsch;
        sort($menge);
        if ($menge !== $letzte) {
            break;
        }
    }
    $_SESSION['letzte_optionen'][$stufe][$code] = $menge;

    $optionen = array_merge([$code], $falsch);
    shuffle($optionen);
    return $optionen;
}

const STUFEN = ['leicht' => 'Leicht', 'mittel' => 'Mittel', 'schwer' => 'Schwer'];

/** Bezeichnung einer Kombination, z. B. "Umrisse · Mittel · Kurz", "Flaggen · Kurz" oder "1×1 · bis 12 · Blitz". */
function kombi_name(string $spielart, string $stufe, string $modus): string
{
    return match ($spielart) {
        'umrisse' => t('Umrisse') . ' · ' . t(STUFEN[$stufe] ?? $stufe) . ' · ' . modus_name($modus),
        'einmaleins' => t('1×1') . ' · ' . t('bis {n}', ['n' => (int)$stufe]) . ' · ' . modus_name($modus),
        default => t('Flaggen') . ' · ' . modus_name($modus),
    };
}

/** Spielarten mit eigener Rangliste. */
const SPIELARTEN = ['flaggen', 'umrisse', 'einmaleins'];

/** Anzeige der Musterlösung (inkl. Alternativname, wo sinnvoll). */
function loesung_land(string $code): string
{
    if (sprache() !== 'de') {
        $p = sprachpaket();
        return $p['anzeige_land'][$code] ?? $p['laender'][$code][0][0];
    }
    $l = LAENDER[$code][0];
    return match ($code) {
        'by' => 'Belarus (Weissrussland)',
        'md' => 'Moldau (Moldawien)',
        default => $l[0],
    };
}

function loesung_haupt(string $code): string
{
    if (sprache() !== 'de') {
        $p = sprachpaket();
        return $p['anzeige_haupt'][$code] ?? $p['laender'][$code][1][0];
    }
    return match ($code) {
        'ua' => 'Kiew (Kyjiw)',
        'ch' => 'Bern (Bundesstadt)',
        default => LAENDER[$code][1][0],
    };
}

/**
 * Ungenaue, aber verständliche Antworten: zählen als richtig,
 * die korrekte Bezeichnung wird trotzdem angezeigt.
 * Code => [[Land-Varianten], [Hauptstadt-Varianten]]
 */
const TOLERIERT = [
    'ba' => [['Bosnien'], []],
    'cz' => [['Tschechei'], []],
    'gb' => [['Vereintes Königreich', 'Britannien', 'Grossbritanien'], []],
    'mk' => [['Mazedonien'], []],
    'nl' => [['Holland'], []],
    'md' => [['Moldavien'], ['Kischinjow']],
    'ru' => [['Russische Föderation'], []],
];

/** Vereinheitlicht eine Eingabe für den Vergleich (klein, ohne Umlaute/Akzente/Leerzeichen). */
function norm(string $s): string
{
    $s = trim(mb_strtolower($s, 'UTF-8'));
    $s = strtr($s, [
        'ß' => 'ss', 'ä' => 'a', 'ö' => 'o', 'ü' => 'u',
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ă' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u',
        'ș' => 's', 'ş' => 's', 'š' => 's', 'ț' => 't', 'ţ' => 't',
        'č' => 'c', 'ć' => 'c', 'ç' => 'c', 'ž' => 'z', 'ł' => 'l', 'ñ' => 'n', 'ý' => 'y',
        '&' => 'und',
    ]);
    // ae/oe/ue (Schreibweise ohne Umlaut-Taste) wie ä/ö/ü behandeln
    $s = str_replace(['ae', 'oe', 'ue'], ['a', 'o', 'u'], $s);
    // Leerzeichen, Bindestriche, Punkte usw. ignorieren
    return preg_replace('/[^a-z]/', '', $s) ?? '';
}

/**
 * Kölner Phonetik: deutscher Lautcode. Gleich klingende Wörter
 * (Lichtenstein / Liechtenstein, Warshau / Warschau) erhalten denselben Code.
 */
function koelner(string $wort): string
{
    $w = strtoupper(norm($wort));
    $n = strlen($w);
    $code = '';
    for ($i = 0; $i < $n; $i++) {
        $c = $w[$i];
        $vor = $i > 0 ? $w[$i - 1] : '';
        $nach = $i < $n - 1 ? $w[$i + 1] : '';
        switch ($c) {
            case 'A': case 'E': case 'I': case 'J': case 'O': case 'U': case 'Y':
                $z = '0'; break;
            case 'H':
                $z = ''; break;
            case 'B':
                $z = '1'; break;
            case 'P':
                $z = $nach === 'H' ? '3' : '1'; break;
            case 'D': case 'T':
                $z = in_array($nach, ['C', 'S', 'Z'], true) ? '8' : '2'; break;
            case 'F': case 'V': case 'W':
                $z = '3'; break;
            case 'G': case 'K': case 'Q':
                $z = '4'; break;
            case 'C':
                if ($i === 0) {
                    $z = in_array($nach, ['A', 'H', 'K', 'L', 'O', 'Q', 'R', 'U', 'X'], true) ? '4' : '8';
                } else {
                    $z = in_array($nach, ['A', 'H', 'K', 'O', 'Q', 'U', 'X'], true) && !in_array($vor, ['S', 'Z'], true) ? '4' : '8';
                }
                break;
            case 'X':
                $z = in_array($vor, ['C', 'K', 'Q'], true) ? '8' : '48'; break;
            case 'L':
                $z = '5'; break;
            case 'M': case 'N':
                $z = '6'; break;
            case 'R':
                $z = '7'; break;
            case 'S': case 'Z':
                $z = '8'; break;
            default:
                $z = '';
        }
        $code .= $z;
    }
    // doppelte Ziffern zusammenfassen, Nullen ausser am Anfang entfernen
    $code = preg_replace('/(\d)\1+/', '$1', $code) ?? '';
    return $code === '' ? '' : $code[0] . str_replace('0', '', substr($code, 1));
}

/**
 * Tippfehler-Abstand (Damerau-Levenshtein, OSA-Variante): Einfügen, Löschen,
 * Ersetzen und Vertauschen zweier Nachbarbuchstaben zählen je 1 Fehler.
 */
function tippabstand(string $a, string $b): int
{
    $la = strlen($a);
    $lb = strlen($b);
    $d = [];
    for ($i = 0; $i <= $la; $i++) {
        $d[$i][0] = $i;
    }
    for ($j = 0; $j <= $lb; $j++) {
        $d[0][$j] = $j;
    }
    for ($i = 1; $i <= $la; $i++) {
        for ($j = 1; $j <= $lb; $j++) {
            $k = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
            $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $k);
            if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
            }
        }
    }
    return $d[$la][$lb];
}

/** Wie viele Tippfehler bei einem Wort dieser Länge noch akzeptiert werden. */
function erlaubte_fehler(int $laenge): int
{
    return $laenge <= 3 ? 0 : ($laenge <= 5 ? 1 : ($laenge <= 9 ? 2 : 3));
}

/**
 * Abstand einer Eingabe zu einer Liste von Namen: [Abstand, Buchstaben-Abweichung]
 * Abstand 0 = exakt, 1..3 = Anzahl Tippfehler (gleicher Klang zählt als 1), null = zu weit weg.
 * Die Buchstaben-Abweichung entscheidet bei Gleichstand.
 */
function abstand(string $e, string $ep, array $namen): ?array
{
    $best = null;
    foreach ($namen as $name) {
        $n = norm($name);
        if ($n === '') {
            continue;
        }
        $roh = tippabstand($e, $n);
        $d = $roh <= erlaubte_fehler(strlen($n)) ? $roh : null;
        // Gleicher Klang zählt wie ein einzelner Tippfehler – aber nur, wenn sich
        // die Wörter auch in der Schreibweise noch ähneln (höchstens 40 % abweichend).
        if (($d === null || $d > 1) && strlen($n) >= 4 && $ep !== '' && $ep === koelner($name)
            && $roh <= max(2, (int)ceil(strlen($n) * 0.4))) {
            $d = 1;
        }
        if ($d !== null && ($best === null || [$d, $roh] < $best)) {
            $best = [$d, $roh];
        }
    }
    return $best; // [Abstand, reine Buchstaben-Abweichung] oder null
}

/**
 * Prüft eine Antwort zu einer Flagge.
 * $feld: 0 = Land, 1 = Hauptstadt
 * Rückgabe: 'richtig' (exakt), 'tippfehler' (zählt, aber Schreibweise/Bezeichnung
 * ungenau) oder 'falsch'.
 */
function pruefe(string $eingabe, string $code, int $feld): string
{
    $e = norm($eingabe);
    if ($e === '') {
        return 'falsch';
    }
    $alleNamen = namen();
    $alleToleriert = toleriert();
    $offiziell = $alleNamen[$code][$feld];
    $toleriert = $alleToleriert[$code][$feld] ?? [];

    foreach ($offiziell as $a) {
        if ($e === norm($a)) {
            return 'richtig';
        }
    }

    // Ist die Eingabe der Name eines anderen Landes / einer anderen Hauptstadt? Dann falsch.
    $andere = [];
    foreach ($alleNamen as $c => $daten) {
        if ($c === $code) {
            continue;
        }
        $namen = array_merge($daten[$feld], $alleToleriert[$c][$feld] ?? []);
        // gleicher Name wie die eigene Lösung (z. B. Rom beim Vatikan) nicht als "anderes" werten
        $namen = array_filter($namen, function ($n) use ($offiziell, $toleriert) {
            foreach (array_merge($offiziell, $toleriert) as $eigen) {
                if (norm($eigen) === norm($n)) {
                    return false;
                }
            }
            return true;
        });
        foreach ($namen as $n) {
            if ($e === norm($n)) {
                return 'falsch';
            }
        }
        $andere[] = $namen;
    }

    foreach ($toleriert as $a) {
        if ($e === norm($a)) {
            return 'tippfehler';
        }
    }

    if (!TIPPFEHLER_TOLERANZ) {
        return 'falsch';
    }

    // Ähnlichkeit zur richtigen Lösung …
    // Der Lautvergleich (Kölner Phonetik) folgt deutschen Ausspracheregeln: nur auf Deutsch
    $ep = sprache() === 'de' ? koelner($eingabe) : '';
    $dEigen = abstand($e, $ep, array_merge($offiziell, $toleriert));
    if ($dEigen === null) {
        return 'falsch';
    }
    // … aber nur, wenn die Eingabe nicht mindestens so gut zu einem anderen Land passt
    // (z. B. "Irlant" bei der Flagge von Island bleibt falsch).
    foreach ($andere as $namen) {
        $d = abstand($e, $ep, $namen);
        if ($d !== null && $d <= $dEigen) { // Array-Vergleich: erst Abstand, dann Abweichung
            return 'falsch';
        }
    }
    return 'tippfehler';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_DATEI);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_DATEI);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $hatTabelle = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'resultate'")->fetchColumn();
        if (!$hatTabelle) {
            // Neue Installation: Grundtabelle anlegen und alle Updates sofort ausführen (ohne GO)
            $pdo->exec('CREATE TABLE resultate (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                zeit TEXT NOT NULL,
                name TEXT NOT NULL,
                modus TEXT NOT NULL,
                anzahl INTEGER NOT NULL,
                land_richtig INTEGER NOT NULL,
                haupt_richtig INTEGER NOT NULL,
                punkte INTEGER NOT NULL,
                max_punkte INTEGER NOT NULL,
                prozent REAL NOT NULL,
                dauer_sek INTEGER NOT NULL,
                details TEXT NOT NULL
            )');
            $pdo->exec('PRAGMA user_version = 1');
            db_migrieren($pdo);
        }
    }
    return $pdo;
}

/*
 * ---- Datenbank-Version und Update ----
 * Jede Version ist ein Schritt; ein bestehende Datenbank wird über die Seite update.php
 * (mit Sicherungskopie) Schritt für Schritt nachgeführt.
 */
const DB_VERSION = 4;

/** Die Update-Schritte: Version => [Beschreibung, Funktion]. */
function db_schritte(): array
{
    return [
        2 => ['Spielart und Stufe für die Umrisse', function (PDO $p): void {
            $spalten = array_column($p->query('PRAGMA table_info(resultate)')->fetchAll(), 'name');
            if (!in_array('spielart', $spalten, true)) {
                $p->exec("ALTER TABLE resultate ADD COLUMN spielart TEXT NOT NULL DEFAULT 'flaggen'");
            }
            if (!in_array('stufe', $spalten, true)) {
                $p->exec("ALTER TABLE resultate ADD COLUMN stufe TEXT NOT NULL DEFAULT ''");
            }
            $p->exec('CREATE INDEX IF NOT EXISTS idx_rangliste ON resultate (spielart, stufe, modus)');
        }],
        3 => ['Profile und angemeldete Geräte', function (PDO $p): void {
            $p->exec('CREATE TABLE IF NOT EXISTS profile (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL,
                avatar TEXT NOT NULL,
                sprache TEXT NOT NULL DEFAULT \'de\',
                link_hash TEXT NOT NULL UNIQUE,
                erstellt TEXT NOT NULL,
                letzte_anmeldung TEXT
            )');
            $p->exec('CREATE INDEX IF NOT EXISTS idx_profile_email ON profile (email)');
            $p->exec('CREATE TABLE IF NOT EXISTS geraete (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                profil_id INTEGER NOT NULL REFERENCES profile(id) ON DELETE CASCADE,
                token_hash TEXT NOT NULL UNIQUE,
                erstellt TEXT NOT NULL,
                zuletzt TEXT NOT NULL
            )');
            $p->exec('CREATE TABLE IF NOT EXISTS drossel (
                schluessel TEXT NOT NULL,
                zeit INTEGER NOT NULL
            )');
            $p->exec('CREATE INDEX IF NOT EXISTS idx_drossel ON drossel (schluessel, zeit)');
            $spalten = array_column($p->query('PRAGMA table_info(resultate)')->fetchAll(), 'name');
            if (!in_array('profil_id', $spalten, true)) {
                $p->exec('ALTER TABLE resultate ADD COLUMN profil_id INTEGER');
            }
            $p->exec('CREATE INDEX IF NOT EXISTS idx_resultate_profil ON resultate (profil_id)');
        }],
        4 => ['Lernstand für das 1×1', function (PDO $p): void {
            // pro Profil und Rechnung (a × b): wie oft richtig/falsch, Kästchen 0–5, Antwortzeit
            $p->exec('CREATE TABLE IF NOT EXISTS rechnungen (
                profil_id INTEGER NOT NULL REFERENCES profile(id) ON DELETE CASCADE,
                a INTEGER NOT NULL,
                b INTEGER NOT NULL,
                richtig INTEGER NOT NULL DEFAULT 0,
                falsch INTEGER NOT NULL DEFAULT 0,
                kasten INTEGER NOT NULL DEFAULT 0,
                zuletzt TEXT NOT NULL,
                ms_summe INTEGER NOT NULL DEFAULT 0,
                ms_anzahl INTEGER NOT NULL DEFAULT 0,
                PRIMARY KEY (profil_id, a, b)
            )');
        }],
    ];
}

/** Führt alle fehlenden Schritte aus (in einer Transaktion). */
function db_migrieren(PDO $pdo): void
{
    $ist = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    $pdo->beginTransaction();
    try {
        foreach (db_schritte() as $version => [, $schritt]) {
            if ($version > $ist) {
                $schritt($pdo);
                $pdo->exec('PRAGMA user_version = ' . (int)$version);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function db_version(): int
{
    return (int)db()->query('PRAGMA user_version')->fetchColumn();
}

function update_noetig(): bool
{
    return db_version() < DB_VERSION;
}

/** Beschreibungen der noch fehlenden Update-Schritte. */
function update_schritte_offen(): array
{
    $ist = db_version();
    $offen = [];
    foreach (db_schritte() as $version => [$text]) {
        if ($version > $ist) {
            $offen[] = $text;
        }
    }
    return $offen;
}

/**
 * Bringt eine bestehende Datenbank auf den aktuellen Stand.
 * Vorher wird eine Sicherungskopie im Ordner data/ angelegt; alle Resultate bleiben erhalten.
 * Gibt den Dateinamen der Sicherung zurück.
 */
function db_update(): string
{
    $pdo = db();
    $sicherung = '';
    if (is_file(DB_DATEI)) {
        $sicherung = dirname(DB_DATEI) . '/resultate-sicherung-' . date('Ymd-His') . '.sqlite';
        // Zuerst alles aus dem Zwischenspeicher in die Datei schreiben, dann kopieren
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        if (!copy(DB_DATEI, $sicherung)) {
            throw new RuntimeException(t('Sicherungskopie konnte nicht angelegt werden (Ordner data/ beschreibbar?).'));
        }
    }
    db_migrieren($pdo);
    return basename($sicherung);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function dauer(int $s): string
{
    return sprintf('%d:%02d min', intdiv($s, 60), $s % 60);
}

function modus_name(string $modus): string
{
    return match ($modus) {
        'kurz' => t('Kurz'),
        'blitz' => t('Blitz'),
        default => t('Komplett'),
    };
}

/**
 * Liest Spielart, Stufe und Umfang aus einem Formular/Link und bereinigt sie.
 * Rückgabe: [spielart, stufe, modus]; bei Flaggen ist die Stufe ''.
 */
function kombi_aus(array $quelle, array $standard = ['flaggen', '', 'kurz']): array
{
    $spielart = in_array($quelle['spielart'] ?? '', SPIELARTEN, true) ? $quelle['spielart'] : $standard[0];
    if ($spielart === 'einmaleins') {
        // Stufe = Bereich (10, 12, 15), Umfang = Kurz (20 Aufgaben) oder Blitz (60 Sekunden)
        $stufe = (string)einmaleins_bis($quelle['stufe'] ?? ($standard[0] === 'einmaleins' ? $standard[1] : ''));
        $modus = in_array($quelle['modus'] ?? '', ['kurz', 'blitz'], true) ? $quelle['modus'] : 'kurz';
        return [$spielart, $stufe, $modus];
    }
    $stufe = isset(STUFEN[$quelle['stufe'] ?? '']) ? $quelle['stufe'] : (isset(STUFEN[$standard[1]]) ? $standard[1] : 'mittel');
    $modus = in_array($quelle['modus'] ?? '', ['kurz', 'komplett'], true) ? $quelle['modus'] : ($standard[2] === 'komplett' ? 'komplett' : 'kurz');
    return [$spielart, $spielart === 'umrisse' ? $stufe : '', $modus];
}

/**
 * Rangliste einer Kombination (Spielart + Stufe + Umfang):
 * beste Prozent zuerst, bei Gleichstand die schnellere Zeit.
 * 1×1 Blitz: am meisten richtige zuerst, bei Gleichstand die bessere Quote.
 * Gleicher Wert ergibt den gleichen Rang (1, 2, 2, 4 …).
 */
function rangliste(string $spielart, string $stufe, string $modus): array
{
    $st = db()->prepare('SELECT r.id, r.zeit, COALESCE(p.name, r.name) AS name, r.profil_id, p.avatar,
        r.spielart, r.stufe, r.modus, r.anzahl, r.land_richtig, r.haupt_richtig,
        r.punkte, r.max_punkte, r.prozent, r.dauer_sek
        FROM resultate r LEFT JOIN profile p ON p.id = r.profil_id
        WHERE r.spielart = ? AND r.stufe = ? AND r.modus = ?
        ORDER BY ' . (ist_blitz($spielart, $modus) ? 'r.punkte DESC, r.prozent DESC' : 'r.prozent DESC, r.dauer_sek ASC') . ', r.id ASC');
    $st->execute([$spielart, $spielart === 'flaggen' ? '' : $stufe, $modus]);
    $zeilen = $st->fetchAll();
    $vorher = null;
    foreach ($zeilen as $i => &$z) {
        $schluessel = ist_blitz($spielart, $modus) ? $z['punkte'] . '|' . $z['prozent'] : $z['prozent'] . '|' . $z['dauer_sek'];
        $z['rang'] = ($vorher !== null && $vorher[0] === $schluessel) ? $vorher[1] : $i + 1;
        $vorher = [$schluessel, $z['rang']];
    }
    unset($z);
    return $zeilen;
}

function ist_blitz(string $spielart, string $modus): bool
{
    return $spielart === 'einmaleins' && $modus === 'blitz';
}

/** Name in Ranglisten: mit Profil-Icon, oder als Gast markiert. */
function spieler_name(array $z): string
{
    if (!empty($z['profil_id']) && !empty($z['avatar'])) {
        return '<span class="spieler">' . avatar_svg($z['avatar'], 22) . h($z['name']) . '</span>';
    }
    return '<span class="spieler">' . h($z['name']) . ' <span class="gast">' . h(t('Gast')) . '</span></span>';
}

/** Gibt eine Ranglisten-Tabelle aus; die Zeile mit $markiert wird hervorgehoben. */
function rangliste_tabelle(array $zeilen, string $spielart, ?int $markiert = null, string $badge = ''): void
{
    if (!$zeilen) {
        echo '<p class="leer">' . h(t('Noch keine Ergebnisse.')) . '</p>';
        return;
    }
    $badge = $badge !== '' ? $badge : t('neu');
    $umrisse = $spielart !== 'flaggen'; // Umrisse und 1×1: eine Spalte «Richtig»
    echo '<table class="liste"><thead><tr><th class="r">' . h(t('Rang')) . '</th><th>' . h(t('Name')) . '</th>'
        . ($umrisse ? '<th>' . h(t('Richtig')) . '</th>'
            : '<th>' . h(t('Länder')) . '</th><th>' . h(t('Hauptstädte')) . '</th><th>' . h(t('Punkte')) . '</th>')
        . '<th class="r">%</th><th>' . h(t('Dauer')) . '</th><th>' . h(t('Datum')) . '</th><th></th></tr></thead><tbody>';
    foreach ($zeilen as $z) {
        $m = $markiert !== null && (int)$z['id'] === $markiert;
        $medaille = ['', '🥇', '🥈', '🥉'][(int)$z['rang']] ?? '';
        echo '<tr' . ($m ? ' class="markiert"' : '') . '>'
            . '<td class="r rang">' . ($medaille ?: (int)$z['rang'] . '.') . '</td>'
            . '<td>' . spieler_name($z) . ($m ? ' <span class="du">' . h($badge) . '</span>' : '') . '</td>'
            . ($umrisse
                ? '<td>' . (int)$z['land_richtig'] . '/' . (int)$z['anzahl'] . '</td>'
                : '<td>' . (int)$z['land_richtig'] . '/' . (int)$z['anzahl'] . '</td>'
                . '<td>' . (int)$z['haupt_richtig'] . '/' . (int)$z['anzahl'] . '</td>'
                . '<td>' . (int)$z['punkte'] . '/' . (int)$z['max_punkte'] . '</td>')
            . '<td class="r"><strong>' . number_format((float)$z['prozent'], 1, '.', '') . '</strong></td>'
            . '<td>' . h(dauer((int)$z['dauer_sek'])) . '</td>'
            . '<td>' . h(date('d.m.Y H:i', strtotime($z['zeit']))) . '</td>'
            . '<td><a href="auswertung.php?id=' . (int)$z['id'] . '">' . h(t('Details')) . '</a></td>'
            . '</tr>';
    }
    echo '</tbody></table>';
}

function kopf(string $titel): void
{
    $thema = thema();
    echo '<!doctype html><html lang="' . (sprache() === 'fr' ? 'fr-CH' : 'de-CH') . '"'
        . ($thema !== 'auto' ? ' data-theme="' . $thema . '"' : '') . '><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="' . ['auto' => 'light dark', 'hell' => 'light', 'dunkel' => 'dark'][$thema] . '">'
        . '<title>' . h($titel === APP_NAME ? $titel : $titel . ' · ' . APP_NAME) . '</title>'
        . '<link rel="icon" href="appicon/favicon.svg" type="image/svg+xml">'
        . '<link rel="icon" href="appicon/favicon-32.png" sizes="32x32" type="image/png">'
        . '<link rel="apple-touch-icon" href="appicon/apple-touch-icon.png">'
        . '<link rel="manifest" href="appicon/manifest.webmanifest">'
        . '<meta name="theme-color" content="#1f4fa3">'
        . '<meta name="apple-mobile-web-app-title" content="' . APP_NAME . '">'
        . '<link rel="stylesheet" href="style.css"></head><body>'
        . '<header class="app-kopf"><a class="app-marke" href="index.php">'
        . '<img src="appicon/icon.svg" alt="" width="32" height="32"><span>' . APP_NAME . '</span></a>'
        . '<div class="kopf-rechts">' . kopf_rechts() . umschalter() . '</div></header>'
        . '<main>';
}

/** Rechts in der Kopfzeile: angemeldetes Profil oder «Profil erstellen». */
function kopf_rechts(): string
{
    $p = profil();
    if ($p !== null) {
        return '<a class="profil-chip" href="profil.php">' . avatar_svg($p['avatar'], 30)
            . '<span class="profil-name">' . h($p['name']) . '</span>' . kopf_extra($p) . '</a>';
    }
    return '<a class="profil-chip ohne" href="profil-neu.php">' . h(t('Profil erstellen')) . '</a>';
}

/** Zusätzliche Anzeige im Profil-Knopf: die Serie (Tage am Stück), ab 2 Tagen. */
function kopf_extra(array $profil): string
{
    [$aktuell] = serie((int)$profil['id']);
    return $aktuell >= 2
        ? ' <span class="serie-chip" title="' . h(t('{n} Tage am Stück geübt', ['n' => $aktuell])) . '">🔥 ' . $aktuell . '</span>'
        : '';
}

function fuss(): void
{
    echo '</main><footer class="app-footer">'
        . '<a href="' . APP_REPO . '/releases" rel="noopener">' . APP_NAME . ' ' . h(APP_VERSION) . '</a>'
        . '</footer></body></html>';
}

// Solange die Datenbank ein Update braucht, führt jede Seite zur Update-Seite.
if (!defined('UPDATE_SEITE') && update_noetig()) {
    header('Location: update.php');
    exit;
}
