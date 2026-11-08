<?php
// Self test for the quiz data and the answer checking. Run: php tests/selftest.php
// Exit code 0 = everything fine. Runs in CI on every pull request and before a release.
declare(strict_types=1);

const UPDATE_SEITE = true;           // no redirect to the update page
$_SERVER['REQUEST_METHOD'] = 'GET';
$tmp = sys_get_temp_dir() . '/brainforge-selftest-' . getmypid();
@mkdir($tmp);
session_save_path($tmp);
require __DIR__ . '/../lib.php';

$fehler = 0;
function pruefen(bool $ok, string $text): void
{
    global $fehler;
    if (!$ok) {
        $fehler++;
        echo "FEHLER: $text\n";
    }
}

$codes = array_keys(LAENDER);
pruefen(count($codes) === 46, 'Es sollten 46 Länder sein, gefunden: ' . count($codes));

// Every country has a flag, an outline and lists of similar countries/flags
foreach ($codes as $c) {
    pruefen(is_file(__DIR__ . '/../' . flagge($c)), "Flagge fehlt: $c");
    pruefen(is_file(__DIR__ . '/../' . umriss($c)), "Umriss fehlt: $c");
    foreach (['AEHNLICH_LAND' => AEHNLICH_LAND, 'AEHNLICH_FLAGGE' => AEHNLICH_FLAGGE] as $name => $liste) {
        pruefen(count($liste[$c] ?? []) >= 3, "$name: zu wenige Einträge für $c");
        foreach ($liste[$c] ?? [] as $x) {
            pruefen(isset(LAENDER[$x]) && $x !== $c, "$name: ungültiger Eintrag $x bei $c");
        }
    }
}

// Answer checking, for every language: every official name counts as correct; the name
// of another country never counts at the flag of a different country
foreach (array_keys(SPRACHEN) as $sp) {
    sprache($sp);
    $alleNamen = namen();
    $alleToleriert = toleriert();
    pruefen(array_keys($alleNamen) == $codes || count(array_diff($codes, array_keys($alleNamen))) === 0, "[$sp] Länderliste unvollständig");
    foreach ($codes as $a) {
        pruefen(!empty($alleNamen[$a][0][0]) && !empty($alleNamen[$a][1][0]), "[$sp] Name oder Hauptstadt fehlt: $a");
        pruefen(loesung_land($a) !== '' && loesung_haupt($a) !== '', "[$sp] Lösung fehlt: $a");
    }
    foreach ($alleNamen as $a => $daten) {
        foreach ([0, 1] as $feld) {
            foreach ($daten[$feld] as $name) {
                pruefen(pruefe($name, $a, $feld) === 'richtig', "[$sp] Nicht als richtig erkannt: $a «{$name}»");
            }
            foreach ($alleToleriert[$a][$feld] ?? [] as $name) {
                pruefen(pruefe($name, $a, $feld) !== 'falsch', "[$sp] Tolerierte Antwort nicht akzeptiert: $a «{$name}»");
            }
            $eigen = array_map('norm', array_merge($daten[$feld], $alleToleriert[$a][$feld] ?? []));
            foreach ($alleNamen as $b => $andere) {
                if ($a === $b) {
                    continue;
                }
                foreach (array_merge($andere[$feld], $alleToleriert[$b][$feld] ?? []) as $name) {
                    if (!in_array(norm($name), $eigen, true)) {
                        pruefen(pruefe($name, $a, $feld) === 'falsch', "[$sp] Anderes Land akzeptiert: Flagge $a, Eingabe «{$name}»");
                    }
                }
            }
        }
    }
}

// Examples from the tolerant checking
sprache('de');
foreach ([['li', 'Lichtenstein', 'tippfehler'], ['gb', 'Vereintes Königreich', 'tippfehler'], ['by', 'Weissrussland', 'richtig'],
          ['ba', 'Bosnien', 'tippfehler'], ['is', 'Irland', 'falsch'], ['sk', 'Slowenien', 'falsch'], ['gb', 'England', 'falsch']] as [$c, $e, $soll]) {
    pruefen(pruefe($e, $c, 0) === $soll, "[de] «{$e}» bei $c sollte $soll sein, ist " . pruefe($e, $c, 0));
}
sprache('fr');
foreach ([['li', 'Lichtenstein', 'tippfehler'], ['gb', 'royaume uni', 'richtig'], ['by', 'Bielorussie', 'richtig'], ['ch', 'suisse', 'richtig'],
          ['ba', 'Bosnie', 'tippfehler'], ['is', 'Irlande', 'falsch'], ['sk', 'Slovénie', 'falsch'], ['gb', 'Angleterre', 'falsch'],
          ['de', 'Allemagne', 'richtig'], ['de', 'Deutschland', 'falsch'], ['nl', 'Pays Bas', 'richtig']] as [$c, $e, $soll]) {
    pruefen(pruefe($e, $c, 0) === $soll, "[fr] «{$e}» bei $c sollte $soll sein, ist " . pruefe($e, $c, 0));
}
foreach ([['pl', 'Varsovie', 'richtig'], ['dk', 'Copenhage', 'tippfehler'], ['at', 'Wien', 'falsch'], ['ch', 'Berne', 'richtig']] as [$c, $e, $soll]) {
    pruefen(pruefe($e, $c, 1) === $soll, "[fr] Hauptstadt «{$e}» bei $c sollte $soll sein, ist " . pruefe($e, $c, 1));
}
sprache('de');

// Every text passed to t() has a translation in every language file
$texte = [];
foreach (array_merge(glob(__DIR__ . '/../*.php'), glob(__DIR__ . '/../inc/*.php'), glob(__DIR__ . '/../lang/*.php')) as $datei) {
    preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", file_get_contents($datei), $m);
    foreach ($m[1] as $text) {
        $texte[stripslashes($text)] = true;
    }
}
foreach (array_merge(array_values(STUFEN), array_values(THEMEN), array_column(db_schritte(), 0)) as $text) {
    $texte[$text] = true; // werden als t(STUFEN[...]), t(THEMEN[...]) bzw. t($schritt) übersetzt
}
foreach (array_keys(SPRACHEN) as $sp) {
    if ($sp === 'de') {
        continue;
    }
    $uebersetzt = sprachpaket($sp)['texte'];
    foreach (array_keys($texte) as $text) {
        pruefen(isset($uebersetzt[$text]), "[$sp] Übersetzung fehlt: «{$text}»");
    }
    foreach ($uebersetzt as $de => $x) {
        preg_match_all('/\{\w+\}/', $de, $soll);
        preg_match_all('/\{\w+\}/', $x, $ist);
        sort($soll[0]);
        sort($ist[0]);
        pruefen($soll[0] === $ist[0], "[$sp] Platzhalter stimmen nicht: «{$de}»");
        pruefen(isset($texte[$de]), "[$sp] Übersetzung wird nicht verwendet: «{$de}»");
    }
}
pruefen(count($texte) > 50, 'Zu wenige Texte gefunden (' . count($texte) . ') – Suchmuster prüfen');

// Answer options: 4 different countries incl. the correct one, never the same set twice in a row
foreach (['leicht', 'mittel'] as $stufe) {
    foreach ($codes as $c) {
        $vorher = null;
        for ($i = 0; $i < 6; $i++) {
            $o = antwortmoeglichkeiten($c, $stufe);
            pruefen(count($o) === 4 && count(array_unique($o)) === 4 && in_array($c, $o, true), "Antwortmöglichkeiten ungültig: $stufe $c");
            $set = array_values(array_diff($o, [$c]));
            sort($set);
            pruefen($set !== $vorher, "Zweimal dieselbe Auswahl hintereinander: $stufe $c");
            $vorher = $set;
        }
    }
}

// Profile icons: valid seeds, same seed = same picture, different seeds differ
pruefen(avatar_gueltig(avatar_neu()) && !avatar_gueltig('xyz') && !avatar_gueltig('"><svg'), 'Avatar-Seed-Prüfung');
pruefen(avatar_svg('0a1b2c3d') === avatar_svg('0a1b2c3d'), 'Avatar nicht reproduzierbar');
$bilder = [];
for ($i = 0; $i < 40; $i++) {
    $bilder[avatar_svg(sprintf('%08x', $i * 2654435761 % 4294967296))] = true;
}
pruefen(count($bilder) >= 30, 'Avatare zu wenig verschieden: ' . count($bilder));
pruefen(str_contains(avatar_svg('ungueltig'), '<svg') && !str_contains(avatar_svg('"><x'), '"><x'), 'Ungültiger Avatar-Seed wird nicht abgefangen');

// Personal links: long random keys, only hashes stored
$k1 = zufallsschluessel();
pruefen(strlen($k1) === 32 && preg_match('/^[A-Za-z0-9_-]+$/', $k1) && $k1 !== zufallsschluessel(), 'Schlüssel ungültig');
pruefen(schluessel_hash($k1) === hash('sha256', $k1) && schluessel_hash($k1) !== $k1, 'Schlüssel-Hash');
pruefen(spitzname("  Max\t\n Muster  ") === 'Max Muster' && mb_strlen(spitzname(str_repeat('ä', 50))) === 30, 'Spitzname bereinigen');

// Database migration from version 1 (old installations) keeps results and adds the profile tables
$alt = new PDO('sqlite::memory:');
$alt->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$alt->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$alt->exec('CREATE TABLE resultate (id INTEGER PRIMARY KEY AUTOINCREMENT, zeit TEXT NOT NULL, name TEXT NOT NULL, modus TEXT NOT NULL,
    anzahl INTEGER NOT NULL, land_richtig INTEGER NOT NULL, haupt_richtig INTEGER NOT NULL, punkte INTEGER NOT NULL,
    max_punkte INTEGER NOT NULL, prozent REAL NOT NULL, dauer_sek INTEGER NOT NULL, details TEXT NOT NULL)');
$alt->exec("INSERT INTO resultate VALUES (NULL, '2025-01-01 10:00:00', 'Alt', 'kurz', 15, 10, 9, 19, 30, 63.3, 200, '[]')");
$alt->exec('PRAGMA user_version = 1');
db_migrieren($alt);
pruefen((int)$alt->query('PRAGMA user_version')->fetchColumn() === DB_VERSION, 'Migration: Version nicht aktuell');
$z = $alt->query('SELECT * FROM resultate')->fetch();
pruefen($z['name'] === 'Alt' && $z['spielart'] === 'flaggen' && $z['profil_id'] === null, 'Migration: altes Resultat verändert');
foreach (['profile', 'geraete', 'drossel'] as $tabelle) {
    pruefen((bool)$alt->query("SELECT 1 FROM sqlite_master WHERE name = '$tabelle'")->fetchColumn(), "Migration: Tabelle $tabelle fehlt");
}

pruefen((bool)preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', APP_VERSION), 'APP_VERSION hat nicht die Form 1.2.3');

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
echo $fehler === 0 ? "OK – alle Prüfungen bestanden.\n" : "$fehler Fehler.\n";
exit($fehler === 0 ? 0 : 1);
