<?php
/*
 * Das 1×1: Aufgaben auswählen (falsche und unsichere öfter), Lernstand pro Profil
 * und die Statistik (Farbkarte, Abzeichen, Serie, Verlauf).
 *
 * Lernstand: Jede Rechnung (z. B. 7 × 8) hat pro Profil ein «Kästchen» von 0 bis 5.
 * Richtig = ein Kästchen höher, falsch = zurück auf 0. Je tiefer das Kästchen,
 * desto öfter kommt die Rechnung in den nächsten Runden. Ab Kästchen 3 «sitzt» sie.
 */
declare(strict_types=1);

const EINMALEINS_BIS = [10, 12, 15];   // Bereich: bis 10×, 12× oder 15×
const EINMALEINS_KURZ = 20;            // Aufgaben in einer Runde «Kurz»
const BLITZ_SEKUNDEN = 60;             // Dauer einer Runde «Blitz»
const KASTEN_MAX = 5;
const KASTEN_SITZT = 3;

/** Bereich aus einem Formular oder Link (10, 12 oder 15). */
function einmaleins_bis(mixed $wert): int
{
    return in_array((int)$wert, EINMALEINS_BIS, true) ? (int)$wert : EINMALEINS_BIS[0];
}

/** Lernstand eines Profils: "a×b" => [a, b, richtig, falsch, kasten, zuletzt, ms_summe, ms_anzahl]. */
function lernstand(int $profilId): array
{
    $st = db()->prepare('SELECT a, b, richtig, falsch, kasten, zuletzt, ms_summe, ms_anzahl FROM rechnungen WHERE profil_id = ?');
    $st->execute([$profilId]);
    $stand = [];
    foreach ($st as $z) {
        $stand[$z['a'] . '×' . $z['b']] = $z;
    }
    return $stand;
}

/** Wie oft eine Rechnung gezogen wird (relativ). Ohne Profil zählt nur die Rechnung selbst. */
function rechnung_gewicht(int $a, int $b, ?array $stand, bool $mitProfil): float
{
    // ×1 und ×10 sind leicht und kommen seltener
    $g = ($a === 1 || $b === 1) ? 0.25 : (($a === 10 || $b === 10) ? 0.5 : 1.0);
    if (!$mitProfil) {
        return $g;
    }
    if ($stand === null) {
        return $g * 1.5; // noch nie gerechnet
    }
    return $g * [4.0, 2.5, 1.6, 1.0, 0.6, 0.35][max(0, min(KASTEN_MAX, (int)$stand['kasten']))];
}

/**
 * Zieht $anzahl Rechnungen aus dem Bereich bis $bis, gewichtet und ohne Doppelte, solange es
 * genug gibt. Mit Profil kommen Rechnungen, die noch geübt werden müssen, sicher vor.
 *
 * @return list<array{a: int, b: int, w: int}> w = 1 für eine Wiederholung
 */
function rechnungen_ziehen(int $bis, int $anzahl, array $stand, bool $mitProfil): array
{
    $pflicht = [];
    if ($mitProfil) {
        // bis zu ein Viertel der Runde: zuletzt falsch beantwortet, am längsten her zuerst
        $ueben = array_filter($stand, fn($s) => (int)$s['kasten'] === 0 && (int)$s['falsch'] > 0
            && (int)$s['a'] <= $bis && (int)$s['b'] <= $bis);
        usort($ueben, fn($x, $y) => strcmp((string)$x['zuletzt'], (string)$y['zuletzt']));
        foreach (array_slice($ueben, 0, intdiv($anzahl, 4)) as $s) {
            $pflicht[$s['a'] . '×' . $s['b']] = ['a' => (int)$s['a'], 'b' => (int)$s['b'], 'w' => 0];
        }
    }
    $gewogen = [];
    for ($a = 1; $a <= $bis; $a++) {
        for ($b = 1; $b <= $bis; $b++) {
            if (isset($pflicht[$a . '×' . $b])) {
                continue;
            }
            $g = rechnung_gewicht($a, $b, $stand[$a . '×' . $b] ?? null, $mitProfil);
            // gewichtete Stichprobe ohne Zurücklegen: Schlüssel u^(1/g), die grössten gewinnen
            $u = max(1e-12, mt_rand() / mt_getrandmax());
            $gewogen[] = [$u ** (1 / $g), $a, $b];
        }
    }
    rsort($gewogen);
    $liste = array_values($pflicht);
    foreach ($gewogen as [, $a, $b]) {
        if (count($liste) >= $anzahl) {
            break;
        }
        $liste[] = ['a' => $a, 'b' => $b, 'w' => 0];
    }
    // Nie zweimal dieselbe Rechnung (oder ihre Tauschaufgabe) direkt hintereinander
    for ($versuch = 0; $versuch < 200; $versuch++) {
        shuffle($liste);
        $ok = true;
        for ($i = 1; $i < count($liste) && $ok; $i++) {
            $ok = !rechnung_gleich($liste[$i], $liste[$i - 1]);
        }
        if ($ok) {
            break;
        }
    }
    return $liste;
}

/** Gleiche Rechnung oder Tauschaufgabe (7 × 8 und 8 × 7). */
function rechnung_gleich(array $x, array $y): bool
{
    return ($x['a'] === $y['a'] && $x['b'] === $y['b']) || ($x['a'] === $y['b'] && $x['b'] === $y['a']);
}

/** Liest eine getippte Antwort: nur Ziffern, sonst null. */
function rechnung_eingabe(string $text): ?int
{
    $text = trim($text);
    return preg_match('/^\d{1,4}$/', $text) ? (int)$text : null;
}

/** Lernstand nachführen (nur mit Profil). */
function rechnung_merken(int $profilId, int $a, int $b, bool $ok, int $ms, ?PDO $pdo = null): void
{
    $ms = max(0, min($ms, 60000));
    ($pdo ?? db())->prepare('INSERT INTO rechnungen (profil_id, a, b, richtig, falsch, kasten, zuletzt, ms_summe, ms_anzahl)
        VALUES (:p, :a, :b, :r, :f, :k, :z, :ms, 1)
        ON CONFLICT (profil_id, a, b) DO UPDATE SET
            richtig = richtig + :r, falsch = falsch + :f,
            kasten = CASE WHEN CAST(:ok AS INTEGER) = 1 THEN MIN(kasten + 1, ' . KASTEN_MAX . ') ELSE 0 END,
            zuletzt = :z, ms_summe = ms_summe + :ms, ms_anzahl = ms_anzahl + 1')
        ->execute([':p' => $profilId, ':a' => $a, ':b' => $b, ':r' => $ok ? 1 : 0, ':f' => $ok ? 0 : 1,
            ':k' => $ok ? 1 : 0, ':ok' => $ok ? 1 : 0, ':z' => jetzt(), ':ms' => $ms]);
}

/** Übernimmt die Antworten einer Gast-Runde in den Lernstand eines Profils. */
function rechnungen_aus_details(int $profilId, array $details): void
{
    foreach ($details as $d) {
        if (isset($d['a'], $d['b'], $d['ok'])) {
            rechnung_merken($profilId, (int)$d['a'], (int)$d['b'], (bool)$d['ok'], (int)($d['ms'] ?? 0));
        }
    }
}

/** Stand einer Rechnung für die Farbkarte: neu, ueben, unsicher oder sitzt. */
function rechnung_stand(?array $s): string
{
    if ($s === null || ((int)$s['richtig'] === 0 && (int)$s['falsch'] === 0)) {
        return 'neu';
    }
    if ((int)$s['kasten'] >= KASTEN_SITZT) {
        return 'sitzt';
    }
    return (int)$s['kasten'] >= 1 ? 'unsicher' : 'ueben';
}

/** Alle Tage mit mindestens einer Runde (neueste zuerst), Format Y-m-d. */
function spieltage(int $profilId): array
{
    $st = db()->prepare('SELECT DISTINCT substr(zeit, 1, 10) AS tag FROM resultate WHERE profil_id = ? ORDER BY tag DESC');
    $st->execute([$profilId]);
    return array_column($st->fetchAll(), 'tag');
}

/**
 * Serie: Tage am Stück mit mindestens einer Runde. Läuft noch, wenn heute oder gestern
 * gespielt wurde. Rückgabe: [aktuelle Serie, längste Serie].
 */
function serie(int $profilId, ?string $heute = null): array
{
    return serie_aus_tagen(spieltage($profilId), $heute ?? date('Y-m-d'));
}

/** Serie aus einer Liste von Spieltagen (neueste zuerst): [aktuelle Serie, längste Serie]. */
function serie_aus_tagen(array $tage, string $heute): array
{
    $laengste = 0;
    $lauf = 0;
    $vorher = null;
    foreach ($tage as $tag) {
        $lauf = ($vorher !== null && $tag === date('Y-m-d', strtotime($vorher . ' -1 day'))) ? $lauf + 1 : 1;
        $laengste = max($laengste, $lauf);
        $vorher = $tag;
    }
    $aktuell = 0;
    $gestern = date('Y-m-d', strtotime($heute . ' -1 day'));
    if ($tage && ($tage[0] === $heute || $tage[0] === $gestern)) {
        $erwartet = $tage[0];
        foreach ($tage as $tag) {
            if ($tag !== $erwartet) {
                break;
            }
            $aktuell++;
            $erwartet = date('Y-m-d', strtotime($tag . ' -1 day'));
        }
    }
    return [$aktuell, $laengste];
}

/** Die 1×1-Runden eines Profils, neueste zuerst. */
function einmaleins_runden(int $profilId, int $limit = 1000): array
{
    $st = db()->prepare("SELECT id, zeit, stufe, modus, anzahl, punkte, prozent, dauer_sek FROM resultate
        WHERE profil_id = ? AND spielart = 'einmaleins' ORDER BY id DESC LIMIT " . max(1, $limit));
    $st->execute([$profilId]);
    return $st->fetchAll();
}

/** Ist eine ganze Reihe (n × 1 … n × 10 und 1 × n … 10 × n) im Kopf? */
function reihe_sitzt(array $stand, int $n): bool
{
    for ($k = 1; $k <= 10; $k++) {
        foreach ([$n . '×' . $k, $k . '×' . $n] as $r) {
            if (rechnung_stand($stand[$r] ?? null) !== 'sitzt') {
                return false;
            }
        }
    }
    return true;
}

/**
 * Abzeichen: alle möglichen, mit Angabe, ob schon erreicht.
 *
 * @return list<array{id: string, symbol: string, titel: string, text: string, erreicht: bool}>
 */
function abzeichen(int $profilId, array $stand, ?array $runden = null, ?array $serie = null): array
{
    $runden ??= einmaleins_runden($profilId);
    [, $laengste] = $serie ?? serie($profilId);
    $n = count($runden);
    $fehlerfrei = false;
    $blitzBest = 0;
    foreach ($runden as $r) {
        if ($r['modus'] === 'kurz' && (int)$r['anzahl'] > 0 && (int)$r['punkte'] === (int)$r['anzahl']) {
            $fehlerfrei = true;
        }
        if ($r['modus'] === 'blitz') {
            $blitzBest = max($blitzBest, (int)$r['punkte']);
        }
    }
    $liste = [
        ['erste', '🎉', t('Los geht’s'), t('Die erste 1×1-Runde gerechnet'), $n >= 1],
        ['zehn', '💪', t('Fleissig'), t('10 Runden 1×1'), $n >= 10],
        ['fuenfzig', '🏋️', t('Ausdauer'), t('50 Runden 1×1'), $n >= 50],
        ['fehlerfrei', '⭐', t('Fehlerfrei'), t('Eine Runde «Kurz» ohne Fehler'), $fehlerfrei],
        ['blitz25', '⚡', t('Blitzschnell'), t('25 richtige in einer Blitz-Runde'), $blitzBest >= 25],
        ['blitz40', '🚀', t('Turbo'), t('40 richtige in einer Blitz-Runde'), $blitzBest >= 40],
        ['serie3', '🔥', t('Dranbleiben'), t('3 Tage am Stück geübt'), $laengste >= 3],
        ['serie7', '🏆', t('Eine Woche'), t('7 Tage am Stück geübt'), $laengste >= 7],
    ];
    foreach (range(2, 9) as $reihe) {
        $liste[] = ['reihe' . $reihe, (string)$reihe, t('{n}er-Reihe', ['n' => $reihe]), t('Alle Rechnungen der {n}er-Reihe sitzen', ['n' => $reihe]), reihe_sitzt($stand, $reihe)];
    }
    $alle = true;
    for ($a = 1; $a <= 10 && $alle; $a++) {
        for ($b = 1; $b <= 10; $b++) {
            if (rechnung_stand($stand[$a . '×' . $b] ?? null) !== 'sitzt') {
                $alle = false;
                break;
            }
        }
    }
    $liste[] = ['klein', '👑', t('Kleines 1×1'), t('Das ganze kleine 1×1 sitzt'), $alle];
    return array_map(fn($x) => ['id' => $x[0], 'symbol' => $x[1], 'titel' => $x[2], 'text' => $x[3], 'erreicht' => $x[4]], $liste);
}
