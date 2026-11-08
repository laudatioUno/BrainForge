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
foreach (['profile', 'geraete', 'drossel', 'rechnungen'] as $tabelle) {
    pruefen((bool)$alt->query("SELECT 1 FROM sqlite_master WHERE name = '$tabelle'")->fetchColumn(), "Migration: Tabelle $tabelle fehlt");
}

// 1×1: range, combinations, answers
pruefen(einmaleins_bis('12') === 12 && einmaleins_bis('11') === 10 && einmaleins_bis(null) === 10, '1×1: Bereich');
pruefen(kombi_aus(['spielart' => 'einmaleins', 'stufe' => '15', 'modus' => 'blitz']) === ['einmaleins', '15', 'blitz'], '1×1: Kombination');
pruefen(kombi_aus(['spielart' => 'einmaleins', 'stufe' => 'mittel', 'modus' => 'komplett']) === ['einmaleins', '10', 'noetig'], '1×1: ungültige Kombination');
pruefen(kombi_aus(['spielart' => 'einmaleins', 'stufe' => '12', 'modus' => 'kurz']) === ['einmaleins', '12', 'noetig'], '1×1: alter Link mit Kurz');
pruefen(kombi_aus(['spielart' => 'einmaleins', 'stufe' => '12', 'modus' => 'alle']) === ['einmaleins', '12', 'alle'], '1×1: Alle');
pruefen(kombi_aus(['spielart' => 'umrisse'], ['einmaleins', '12', 'blitz']) === ['umrisse', 'mittel', 'kurz'], '1×1: Wechsel zu Umrisse');
pruefen(rechnung_eingabe(' 56 ') === 56 && rechnung_eingabe('5a') === null && rechnung_eingabe('') === null && rechnung_eingabe('-4') === null, '1×1: Eingabe lesen');

// Drawing: right number, in range, no duplicates (swapped tasks count as the same), never the same task twice in a row
foreach (EINMALEINS_BIS as $bis) {
    for ($i = 0; $i < 20; $i++) {
        $l = rechnungen_ziehen($bis, EINMALEINS_NOETIG_MAX, [], false);
        $schl = array_map(fn($x) => rechnung_schluessel($x['a'], $x['b']), $l);
        pruefen(count($l) === EINMALEINS_NOETIG_MAX && count(array_unique($schl)) === EINMALEINS_NOETIG_MAX, "1×1: Ziehen bis $bis");
        foreach ($l as $j => $x) {
            pruefen($x['a'] >= 1 && $x['a'] <= $bis && $x['b'] >= 1 && $x['b'] <= $bis && $x['w'] === 0, "1×1: ausserhalb des Bereichs bis $bis");
            pruefen($j === 0 || !rechnung_gleich($x, $l[$j - 1]), '1×1: gleiche Rechnung hintereinander');
        }
    }
    // «Alle»: every task exactly once, swapped tasks once
    $l = rechnungen_alle($bis);
    $schl = array_map(fn($x) => rechnung_schluessel($x['a'], $x['b']), $l);
    pruefen(count($l) === paare_anzahl($bis) && count(array_unique($schl)) === paare_anzahl($bis), "1×1: Alle bis $bis");
}
pruefen(paare_anzahl(10) === 55 && paare_anzahl(12) === 78 && paare_anzahl(15) === 120, '1×1: Anzahl Aufgaben');
pruefen(rechnung_schluessel(8, 7) === '7×8' && rechnung_schluessel(7, 8) === '7×8', '1×1: Tauschaufgabe gleicher Schlüssel');
$dreh = 0;
for ($i = 0; $i < 200; $i++) {
    $dreh += rechnungen_alle(10)[0]['a'] > rechnungen_alle(10)[0]['b'] ? 1 : 0;
}
pruefen($dreh > 20 && $dreh < 180, '1×1: Richtung wird nicht gemischt');

// Learning state: tasks to practise come for sure, mastered ones less often
$stand = [];
for ($a = 1; $a <= 10; $a++) {
    for ($b = $a; $b <= 10; $b++) {
        $stand["{$a}×{$b}"] = ['a' => $a, 'b' => $b, 'richtig' => 5, 'falsch' => 0, 'kasten' => 5, 'zuletzt' => '2026-11-01 10:00:00'];
    }
}
$stand['7×8'] = ['a' => 7, 'b' => 8, 'richtig' => 1, 'falsch' => 3, 'kasten' => 0, 'zuletzt' => '2026-11-02 10:00:00'];
$stand['6×9'] = ['a' => 6, 'b' => 9, 'richtig' => 1, 'falsch' => 1, 'kasten' => 0, 'zuletzt' => '2026-11-01 09:00:00'];
for ($i = 0; $i < 10; $i++) {
    $schl = array_map(fn($x) => rechnung_schluessel($x['a'], $x['b']), rechnungen_ziehen(10, EINMALEINS_NOETIG_MAX, $stand, true));
    pruefen(in_array('7×8', $schl, true) && in_array('6×9', $schl, true), '1×1: zu übende Rechnungen fehlen');
}
pruefen(rechnung_gewicht(7, 8, $stand['7×8'], true) > 5 * rechnung_gewicht(7, 7, $stand['7×7'], true), '1×1: Gewicht falsch/sitzt');
pruefen(rechnung_gewicht(1, 8, null, false) < rechnung_gewicht(7, 8, null, false), '1×1: ×1 sollte seltener sein');
pruefen(rechnung_stand(null) === 'neu' && rechnung_stand($stand['7×8']) === 'ueben' && rechnung_stand($stand['7×7']) === 'sitzt'
    && rechnung_stand(['richtig' => 1, 'falsch' => 0, 'kasten' => 1]) === 'unsicher', '1×1: Stand für die Farbkarte');
pruefen(stand_von($stand, 8, 7) === $stand['7×8'], '1×1: Stand der Tauschaufgabe');
pruefen(reihe_sitzt($stand, 2) && !reihe_sitzt($stand, 7) && !reihe_sitzt($stand, 8), '1×1: Reihe gemeistert');
pruefen(reihe_fortschritt($stand, 7) === [9, 10] && reihe_fortschritt($stand, 2) === [10, 10] && reihe_fortschritt($stand, 2, 12) === [10, 12], '1×1: Fortschritt einer Reihe');

// «Nur was nötig ist»: wrong ones first, due ones next (Leitner days), mastered and not due ones never
$heute = '2026-11-03';  // box 5 = due after 16 days: not due yet
[$l, $auffrischen] = rechnungen_noetig(10, $stand, $heute);
$schl = array_map(fn($x) => rechnung_schluessel($x['a'], $x['b']), $l);
sort($schl);
pruefen($schl === ['6×9', '7×8'] && !$auffrischen, '1×1: nötig = nur die falschen');
pruefen(noetig_anzahl(10, $stand, $heute) === 2 && noetig_anzahl(12, $stand, $heute) === EINMALEINS_NOETIG_MAX, '1×1: Anzahl nötig');
$stand['3×4']['kasten'] = 1; // box 1: due after one day
pruefen(count(rechnungen_noetig(10, $stand, '2026-11-01')[0]) === 2 && count(rechnungen_noetig(10, $stand, '2026-11-02')[0]) === 3, '1×1: fällig nach Kästchen');
pruefen(rechnung_faellig(['kasten' => 5, 'zuletzt' => '2026-11-01 10:00:00'], '2026-11-17') && !rechnung_faellig(['kasten' => 5, 'zuletzt' => '2026-11-01 10:00:00'], '2026-11-16'), '1×1: Kästchen 5 nach 16 Tagen');
$sitzt = array_map(fn($s) => ['kasten' => 5] + $s, $stand);
$sitzt['7×8']['kasten'] = $sitzt['6×9']['kasten'] = $sitzt['3×4']['kasten'] = 5;
[$l, $auffrischen] = rechnungen_noetig(10, $sitzt, $heute);
pruefen($auffrischen && count($l) === EINMALEINS_AUFFRISCHEN && !array_filter($l, fn($x) => $x['a'] === 1 || $x['b'] === 1), '1×1: nichts fällig = auffrischen');
$neu = rechnungen_noetig(10, [], $heute)[0];
pruefen(count($neu) === EINMALEINS_NOETIG_MAX, '1×1: neues Profil bekommt 20');

// Best values and record
$runden = [
    ['id' => 5, 'stufe' => '10', 'modus' => 'blitz', 'punkte' => 30, 'anzahl' => 32, 'prozent' => 93.8, 'dauer_sek' => 60],
    ['id' => 4, 'stufe' => '10', 'modus' => 'alle', 'punkte' => 55, 'anzahl' => 55, 'prozent' => 100.0, 'dauer_sek' => 200],
    ['id' => 3, 'stufe' => '10', 'modus' => 'blitz', 'punkte' => 31, 'anzahl' => 33, 'prozent' => 93.9, 'dauer_sek' => 60],
    ['id' => 2, 'stufe' => '10', 'modus' => 'alle', 'punkte' => 55, 'anzahl' => 55, 'prozent' => 100.0, 'dauer_sek' => 180],
    ['id' => 1, 'stufe' => '12', 'modus' => 'noetig', 'punkte' => 10, 'anzahl' => 20, 'prozent' => 50.0, 'dauer_sek' => 100],
];
$best = bestwerte($runden);
pruefen($best[10]['blitz']['id'] === 3 && $best[10]['alle']['id'] === 2 && abs($best[10]['tempo'] - 60 / 33) < 0.01 && $best[12]['blitz'] === null, '1×1: Bestwerte');
$neu = ['id' => 6, 'stufe' => '10', 'modus' => 'blitz', 'punkte' => 32, 'anzahl' => 34, 'prozent' => 94.1, 'dauer_sek' => 60];
pruefen(!ist_rekord($runden[0], $runden) && !ist_rekord($runden[1], $runden) && !ist_rekord($runden[2], $runden)
    && !ist_rekord($runden[4], $runden) && ist_rekord($neu, [$neu, ...$runden]), '1×1: Rekord (nicht beim ersten Mal, nicht bei Nötig)');

// Streak: today or yesterday keeps it running; gaps end it
pruefen(serie_aus_tagen([], '2026-11-08') === [0, 0], 'Serie: leer');
pruefen(serie_aus_tagen(['2026-11-08', '2026-11-07', '2026-11-06', '2026-11-01', '2026-10-31'], '2026-11-08') === [3, 3], 'Serie: heute');
pruefen(serie_aus_tagen(['2026-11-07', '2026-11-06'], '2026-11-08') === [2, 2], 'Serie: gestern läuft noch');
pruefen(serie_aus_tagen(['2026-11-05', '2026-11-04', '2026-11-03', '2026-11-02'], '2026-11-08') === [0, 4], 'Serie: abgebrochen');
pruefen(serie_aus_tagen(['2026-03-01', '2026-02-28'], '2026-03-01') === [2, 2], 'Serie: über das Monatsende');

// Learning state in the database (migration 4): right moves one box up, wrong back to 0
$alt->exec("INSERT INTO profile (name, email, avatar, link_hash, erstellt) VALUES ('P', 'p@example.ch', '0a1b2c3d', 'h', '2026-01-01 10:00:00')");
foreach ([true, true, true, false, true] as $ok) {
    rechnung_merken(1, 7, 8, $ok, 1500, $alt);
}
$z = $alt->query('SELECT * FROM rechnungen WHERE profil_id = 1 AND a = 7 AND b = 8')->fetch();
pruefen((int)$z['richtig'] === 4 && (int)$z['falsch'] === 1 && (int)$z['kasten'] === 1 && (int)$z['ms_anzahl'] === 5 && (int)$z['ms_summe'] === 7500, '1×1: Lernstand speichern');
for ($i = 0; $i < 8; $i++) {
    rechnung_merken(1, 7, 8, true, 999999, $alt);
}
$z = $alt->query('SELECT kasten, ms_summe FROM rechnungen WHERE profil_id = 1 AND a = 7 AND b = 8')->fetch();
pruefen((int)$z['kasten'] === KASTEN_MAX && (int)$z['ms_summe'] === 7500 + 8 * 60000, '1×1: Kästchen höchstens 5, Zeit begrenzt');
rechnung_merken(1, 8, 7, false, 1000, $alt);
$z = $alt->query('SELECT COUNT(*) AS n, MAX(kasten) AS k FROM rechnungen WHERE profil_id = 1')->fetch();
pruefen((int)$z['n'] === 1 && (int)$z['k'] === 0, '1×1: 8 × 7 zählt bei 7 × 8');

// Migration 4 → 5: swapped tasks merged (weaker box counts), Kurz becomes «Nur was nötig ist», daily goal column
$v4 = new PDO('sqlite::memory:');
$v4->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$v4->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$v4->exec('CREATE TABLE resultate (id INTEGER PRIMARY KEY AUTOINCREMENT, zeit TEXT NOT NULL, name TEXT NOT NULL, modus TEXT NOT NULL,
    anzahl INTEGER NOT NULL, land_richtig INTEGER NOT NULL, haupt_richtig INTEGER NOT NULL, punkte INTEGER NOT NULL,
    max_punkte INTEGER NOT NULL, prozent REAL NOT NULL, dauer_sek INTEGER NOT NULL, details TEXT NOT NULL)');
foreach (db_schritte() as $version => [, $schritt]) {
    if ($version >= 2 && $version <= 4) {
        $schritt($v4);
    }
}
$v4->exec('PRAGMA user_version = 4');
$v4->exec("INSERT INTO profile (name, email, avatar, link_hash, erstellt) VALUES ('P', 'p@example.ch', '0a1b2c3d', 'h', '2026-01-01 10:00:00')");
$v4->exec("INSERT INTO rechnungen VALUES (1, 7, 8, 3, 1, 3, '2026-11-01 10:00:00', 3000, 4), (1, 8, 7, 2, 0, 2, '2026-11-05 10:00:00', 2000, 2), (1, 3, 3, 1, 0, 1, '2026-11-02 10:00:00', 900, 1)");
$v4->exec("INSERT INTO resultate (zeit, name, spielart, stufe, modus, anzahl, land_richtig, haupt_richtig, punkte, max_punkte, prozent, dauer_sek, details, profil_id)
    VALUES ('2026-11-05 10:00:00', 'P', 'einmaleins', '10', 'kurz', 20, 18, 0, 18, 20, 90, 80, '[]', 1)");
db_migrieren($v4);
$z = $v4->query("SELECT * FROM rechnungen WHERE a = 7 AND b = 8")->fetch();
pruefen((int)$v4->query('SELECT COUNT(*) FROM rechnungen')->fetchColumn() === 2 && (int)$z['richtig'] === 5 && (int)$z['falsch'] === 1
    && (int)$z['kasten'] === 2 && $z['zuletzt'] === '2026-11-05 10:00:00' && (int)$z['ms_anzahl'] === 6, 'Migration 5: Tauschaufgaben zusammengelegt');
pruefen($v4->query('SELECT modus FROM resultate')->fetchColumn() === 'noetig', 'Migration 5: Kurz wird Nur was nötig ist');
pruefen((int)$v4->query('SELECT tagesziel FROM profile')->fetchColumn() === TAGESZIEL_STANDARD, 'Migration 5: Tagesziel');


pruefen((bool)preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/', APP_VERSION), 'APP_VERSION hat nicht die Form 1.2.3');

array_map('unlink', glob("$tmp/*") ?: []);
@rmdir($tmp);
echo $fehler === 0 ? "OK – alle Prüfungen bestanden.\n" : "$fehler Fehler.\n";
exit($fehler === 0 ? 0 : 1);
