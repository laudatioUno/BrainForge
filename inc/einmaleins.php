<?php
/*
 * Das 1×1: Aufgaben auswählen, Lernstand pro Profil und die Statistik
 * (Farbkarte, Reihen, Tagesziel, Bestwerte, Abzeichen, Serie, Verlauf).
 *
 * Lernstand (Leitner-Kästchen, für die Kinder unsichtbar): Jede Aufgabe hat pro Profil ein
 * Kästchen von 0 bis 5. 7 × 8 und 8 × 7 sind dieselbe Aufgabe (gespeichert als a ≤ b).
 * Richtig = ein Kästchen höher, falsch = zurück auf 0. Ab Kästchen 3 «sitzt» sie.
 * Eine Aufgabe ist wieder fällig, wenn seit der letzten Antwort so viele Tage vergangen
 * sind, wie KASTEN_TAGE für ihr Kästchen angibt.
 */
declare(strict_types=1);

const EINMALEINS_BIS = [10, 12, 15];      // Bereich: bis 10×, 12× oder 15×
const EINMALEINS_MODI = ['alle', 'noetig', 'blitz'];
const EINMALEINS_NOETIG_MAX = 20;         // höchstens so viele Aufgaben bei «Nur was nötig ist»
const EINMALEINS_AUFFRISCHEN = 10;        // so viele, wenn nichts fällig ist
const BLITZ_SEKUNDEN = 60;                // Dauer einer Runde «Blitz»
const KASTEN_MAX = 5;
const KASTEN_SITZT = 3;
const KASTEN_TAGE = [0, 1, 2, 4, 8, 16];  // nach wie vielen Tagen eine Aufgabe wieder fällig ist
const TAGESZIEL_STANDARD = 30;            // Aufgaben pro Tag
const TAGESZIELE = [0, 20, 30, 50, 100];  // Auswahl im Profil (0 = aus)

/** Bereich aus einem Formular oder Link (10, 12 oder 15). */
function einmaleins_bis(mixed $wert): int
{
    return in_array((int)$wert, EINMALEINS_BIS, true) ? (int)$wert : EINMALEINS_BIS[0];
}

/** Schlüssel einer Aufgabe, gleich für die Tauschaufgabe: "7×8" für 7 × 8 und 8 × 7. */
function rechnung_schluessel(int $a, int $b): string
{
    return min($a, $b) . '×' . max($a, $b);
}

/** Anzahl verschiedener Aufgaben bis $bis (Tauschaufgaben einmal): 55, 78, 120. */
function paare_anzahl(int $bis): int
{
    return intdiv($bis * ($bis + 1), 2);
}

/** Lernstand eines Profils: "a×b" (a ≤ b) => [a, b, richtig, falsch, kasten, zuletzt, ms_summe, ms_anzahl]. */
function lernstand(int $profilId): array
{
    $st = db()->prepare('SELECT a, b, richtig, falsch, kasten, zuletzt, ms_summe, ms_anzahl FROM rechnungen WHERE profil_id = ?');
    $st->execute([$profilId]);
    $stand = [];
    foreach ($st as $z) {
        $stand[rechnung_schluessel((int)$z['a'], (int)$z['b'])] = $z;
    }
    return $stand;
}

/** Stand einer Aufgabe (egal in welcher Reihenfolge) oder null. */
function stand_von(array $stand, int $a, int $b): ?array
{
    return $stand[rechnung_schluessel($a, $b)] ?? null;
}

/** Eine Aufgabe in zufälliger Richtung (7 × 8 oder 8 × 7). */
function rechnung_zufaellig(int $a, int $b, int $w = 0): array
{
    return random_int(0, 1) === 1 ? ['a' => $a, 'b' => $b, 'w' => $w] : ['a' => $b, 'b' => $a, 'w' => $w];
}

/** Wie oft eine Aufgabe gezogen wird (relativ). Ohne Profil zählt nur die Aufgabe selbst. */
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

/** Mischt eine Liste so, dass nie dieselbe Aufgabe (oder ihre Tauschaufgabe) direkt hintereinander kommt. */
function rechnungen_mischen(array $liste): array
{
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

/**
 * Zieht $anzahl Aufgaben aus dem Bereich bis $bis, gewichtet und ohne Doppelte (auch keine
 * Tauschaufgaben), solange es genug gibt. Mit Profil kommen Aufgaben, die zuletzt falsch waren,
 * sicher vor. Für Blitz und für Gäste.
 *
 * @return list<array{a: int, b: int, w: int}> w = 1 für eine Wiederholung
 */
function rechnungen_ziehen(int $bis, int $anzahl, array $stand, bool $mitProfil): array
{
    $pflicht = [];
    if ($mitProfil) {
        // bis zu ein Viertel der Runde: zuletzt falsch beantwortet, am längsten her zuerst
        $ueben = array_filter($stand, fn($s) => (int)$s['kasten'] === 0 && (int)$s['falsch'] > 0 && (int)$s['b'] <= $bis);
        usort($ueben, fn($x, $y) => strcmp((string)$x['zuletzt'], (string)$y['zuletzt']));
        foreach (array_slice($ueben, 0, intdiv($anzahl, 4)) as $s) {
            $pflicht[rechnung_schluessel((int)$s['a'], (int)$s['b'])] = rechnung_zufaellig((int)$s['a'], (int)$s['b']);
        }
    }
    $gewogen = [];
    for ($a = 1; $a <= $bis; $a++) {
        for ($b = $a; $b <= $bis; $b++) {
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
        $liste[] = rechnung_zufaellig($a, $b);
    }
    return rechnungen_mischen($liste);
}

/** «Alle»: jede Aufgabe bis $bis genau einmal (Tauschaufgaben einmal), Richtung zufällig. */
function rechnungen_alle(int $bis): array
{
    $liste = [];
    for ($a = 1; $a <= $bis; $a++) {
        for ($b = $a; $b <= $bis; $b++) {
            $liste[] = rechnung_zufaellig($a, $b);
        }
    }
    return rechnungen_mischen($liste);
}

/** Ist eine schon gerechnete Aufgabe heute wieder fällig? */
function rechnung_faellig(array $s, string $heute): bool
{
    $kasten = max(0, min(KASTEN_MAX, (int)$s['kasten']));
    $tage = (strtotime($heute) - strtotime(substr((string)$s['zuletzt'], 0, 10))) / 86400;
    return $tage >= KASTEN_TAGE[$kasten];
}

/**
 * Was ist bis $bis nötig? Zuerst zuletzt falsche, dann fällige (am längsten überfällig zuerst),
 * dann neue Aufgaben (leichte ×1/×10 zuletzt).
 *
 * @return array{falsch: list<array>, faellig: list<array>, neu: list<array>}
 */
function noetig_gruppen(int $bis, array $stand, ?string $heute = null): array
{
    $heute ??= date('Y-m-d');
    $falsch = $faellig = $neu = [];
    for ($a = 1; $a <= $bis; $a++) {
        for ($b = $a; $b <= $bis; $b++) {
            $s = $stand[$a . '×' . $b] ?? null;
            if ($s === null || ((int)$s['richtig'] === 0 && (int)$s['falsch'] === 0)) {
                $neu[] = ['a' => $a, 'b' => $b, 'g' => rechnung_gewicht($a, $b, null, false) + mt_rand() / mt_getrandmax() * 0.2];
            } elseif ((int)$s['kasten'] === 0) {
                $falsch[] = ['a' => $a, 'b' => $b, 'z' => (string)$s['zuletzt']];
            } elseif (rechnung_faellig($s, $heute)) {
                $ueber = (strtotime($heute) - strtotime(substr((string)$s['zuletzt'], 0, 10))) / 86400 - KASTEN_TAGE[(int)$s['kasten']];
                $faellig[] = ['a' => $a, 'b' => $b, 'u' => $ueber + mt_rand() / mt_getrandmax() * 0.5];
            }
        }
    }
    usort($falsch, fn($x, $y) => strcmp($x['z'], $y['z']));
    usort($faellig, fn($x, $y) => $y['u'] <=> $x['u']);
    usort($neu, fn($x, $y) => $y['g'] <=> $x['g']);
    return ['falsch' => $falsch, 'faellig' => $faellig, 'neu' => $neu];
}

/** Wie viele Aufgaben heute bei «Nur was nötig ist» kämen (höchstens EINMALEINS_NOETIG_MAX). */
function noetig_anzahl(int $bis, array $stand, ?string $heute = null): int
{
    $g = noetig_gruppen($bis, $stand, $heute);
    return min(EINMALEINS_NOETIG_MAX, count($g['falsch']) + count($g['faellig']) + count($g['neu']));
}

/**
 * «Nur was nötig ist» mit Profil: höchstens 20 Aufgaben aus den nötigen. Ist nichts fällig,
 * kommen zum Auffrischen die 10 Aufgaben, die am längsten her sind.
 *
 * @return array{0: list<array{a: int, b: int, w: int}>, 1: bool} [Aufgaben, nur Auffrischen?]
 */
function rechnungen_noetig(int $bis, array $stand, ?string $heute = null): array
{
    $g = noetig_gruppen($bis, $stand, $heute);
    $wahl = array_slice(array_merge($g['falsch'], $g['faellig'], $g['neu']), 0, EINMALEINS_NOETIG_MAX);
    $auffrischen = false;
    if (!$wahl) {
        $alle = array_filter($stand, fn($s) => (int)$s['b'] <= $bis && (int)$s['a'] > 1);
        usort($alle, fn($x, $y) => strcmp((string)$x['zuletzt'], (string)$y['zuletzt']));
        $wahl = array_slice($alle, 0, EINMALEINS_AUFFRISCHEN);
        $auffrischen = true;
    }
    $liste = array_map(fn($x) => rechnung_zufaellig((int)$x['a'], (int)$x['b']), $wahl);
    return [rechnungen_mischen($liste), $auffrischen];
}

/** Gleiche Aufgabe oder Tauschaufgabe (7 × 8 und 8 × 7). */
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

/** Lernstand nachführen (nur mit Profil). 8 × 7 wird bei 7 × 8 gemerkt. */
function rechnung_merken(int $profilId, int $a, int $b, bool $ok, int $ms, ?PDO $pdo = null): void
{
    [$a, $b] = [min($a, $b), max($a, $b)];
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

/** Fortschritt einer Reihe bis $bis: [sitzen, Aufgaben] (n × 1 … n × $bis, Tauschaufgaben eingeschlossen). */
function reihe_fortschritt(array $stand, int $n, int $bis = 10): array
{
    $sitzt = 0;
    for ($k = 1; $k <= $bis; $k++) {
        $sitzt += rechnung_stand(stand_von($stand, $n, $k)) === 'sitzt' ? 1 : 0;
    }
    return [$sitzt, $bis];
}

/** Ist eine ganze Reihe (n × 1 … n × 10) im Kopf? */
function reihe_sitzt(array $stand, int $n): bool
{
    [$sitzt, $alle] = reihe_fortschritt($stand, $n, 10);
    return $sitzt === $alle;
}

/** 1×1-Aufgaben pro Tag (Y-m-d => Anzahl, Wiederholungen nicht mitgezählt). */
function aufgaben_pro_tag(int $profilId): array
{
    $st = db()->prepare("SELECT substr(zeit, 1, 10) AS tag, SUM(anzahl) AS n FROM resultate
        WHERE profil_id = ? AND spielart = 'einmaleins' GROUP BY tag");
    $st->execute([$profilId]);
    return array_map('intval', array_column($st->fetchAll(), 'n', 'tag'));
}

/** Tagesziel eines Profils (Aufgaben pro Tag, 0 = aus). */
function tagesziel(array $profil): int
{
    return max(0, (int)($profil['tagesziel'] ?? TAGESZIEL_STANDARD));
}

/** Tagesziel heute: [gerechnet, Ziel] oder null, wenn kein Ziel gesetzt ist. */
function tagesziel_stand(array $profil, ?string $heute = null): ?array
{
    $ziel = tagesziel($profil);
    if ($ziel === 0) {
        return null;
    }
    return [aufgaben_pro_tag((int)$profil['id'])[$heute ?? date('Y-m-d')] ?? 0, $ziel];
}

/**
 * Persönliche Bestwerte je Bereich aus einer Liste von Runden (neueste zuerst).
 *
 * @return array<int, array{blitz: ?array, alle: ?array, tempo: ?float}>
 *   blitz = beste Blitz-Runde (meiste richtig), alle = beste Runde «Alle» (Quote, dann Zeit),
 *   tempo = schnellste Sekunden pro Aufgabe in einer Runde mit mindestens 10 Aufgaben
 */
function bestwerte(array $runden): array
{
    $best = [];
    foreach ($runden as $r) {
        $bis = (int)$r['stufe'];
        $b = &$best[$bis];
        $b ??= ['blitz' => null, 'alle' => null, 'tempo' => null];
        if ($r['modus'] === 'blitz' && ($b['blitz'] === null || (int)$r['punkte'] > (int)$b['blitz']['punkte']
            || ((int)$r['punkte'] === (int)$b['blitz']['punkte'] && (float)$r['prozent'] > (float)$b['blitz']['prozent']))) {
            $b['blitz'] = $r;
        }
        if ($r['modus'] === 'alle' && ($b['alle'] === null || (float)$r['prozent'] > (float)$b['alle']['prozent']
            || ((float)$r['prozent'] === (float)$b['alle']['prozent'] && (int)$r['dauer_sek'] < (int)$b['alle']['dauer_sek']))) {
            $b['alle'] = $r;
        }
        if ((int)$r['anzahl'] >= 10) {
            $tempo = (int)$r['dauer_sek'] / (int)$r['anzahl'];
            $b['tempo'] = $b['tempo'] === null ? $tempo : min($b['tempo'], $tempo);
        }
        unset($b);
    }
    ksort($best);
    return $best;
}

/** Ist die Runde $r ein neuer persönlicher Rekord (Blitz: meiste richtig; Alle: beste Quote/Zeit)? */
function ist_rekord(array $r, array $runden): bool
{
    $frueher = array_values(array_filter($runden, fn($x) => (int)$x['id'] !== (int)$r['id'] && (int)$x['id'] < (int)$r['id']
        && $x['modus'] === $r['modus'] && (string)$x['stufe'] === (string)$r['stufe']));
    if (!$frueher || !in_array($r['modus'], ['blitz', 'alle'], true)) {
        return false;
    }
    $alt = bestwerte($frueher)[(int)$r['stufe']][$r['modus']];
    return $r['modus'] === 'blitz'
        ? (int)$r['punkte'] > (int)$alt['punkte']
        : ((float)$r['prozent'] > (float)$alt['prozent']
            || ((float)$r['prozent'] === (float)$alt['prozent'] && (int)$r['dauer_sek'] < (int)$alt['dauer_sek']));
}

/**
 * Abzeichen: alle möglichen, mit Angabe, ob schon erreicht.
 *
 * @return list<array{id: string, symbol: string, titel: string, text: string, erreicht: bool}>
 */
function abzeichen(int $profilId, array $stand, ?array $runden = null, ?array $serie = null, ?int $ziel = null): array
{
    $runden ??= einmaleins_runden($profilId);
    [, $laengste] = $serie ?? serie($profilId);
    $n = count($runden);
    $fehlerfrei = false;
    $alleFertig = false;
    $blitzBest = 0;
    foreach ($runden as $r) {
        if ($r['modus'] !== 'blitz' && (int)$r['anzahl'] >= 10 && (int)$r['punkte'] === (int)$r['anzahl']) {
            $fehlerfrei = true;
        }
        if ($r['modus'] === 'alle') {
            $alleFertig = true;
        }
        if ($r['modus'] === 'blitz') {
            $blitzBest = max($blitzBest, (int)$r['punkte']);
        }
    }
    $zielTage = 0;
    if ($ziel !== null && $ziel > 0) {
        $zielTage = count(array_filter(aufgaben_pro_tag($profilId), fn($x) => $x >= $ziel));
    }
    $liste = [
        ['erste', '🎉', t('Los geht’s'), t('Die erste 1×1-Runde gerechnet'), $n >= 1],
        ['zehn', '💪', t('Fleissig'), t('10 Runden 1×1'), $n >= 10],
        ['fuenfzig', '🏋️', t('Ausdauer'), t('50 Runden 1×1'), $n >= 50],
        ['fehlerfrei', '⭐', t('Fehlerfrei'), t('Eine Runde mit mindestens 10 Aufgaben ohne Fehler'), $fehlerfrei],
        ['alle', '🧩', t('Alles einmal'), t('Eine Runde «Alle» fertig gerechnet'), $alleFertig],
        ['blitz25', '⚡', t('Blitzschnell'), t('25 richtige in einer Blitz-Runde'), $blitzBest >= 25],
        ['blitz40', '🚀', t('Turbo'), t('40 richtige in einer Blitz-Runde'), $blitzBest >= 40],
        ['ziel', '🎯', t('Ziel erreicht'), t('Das Tagesziel erreicht'), $zielTage >= 1],
        ['ziel10', '📅', t('Zielstrebig'), t('An 10 Tagen das Tagesziel erreicht'), $zielTage >= 10],
        ['serie3', '🔥', t('Dranbleiben'), t('3 Tage am Stück geübt'), $laengste >= 3],
        ['serie7', '🏆', t('Eine Woche'), t('7 Tage am Stück geübt'), $laengste >= 7],
    ];
    foreach (range(2, 9) as $reihe) {
        $liste[] = ['reihe' . $reihe, (string)$reihe, t('{n}er-Reihe', ['n' => $reihe]), t('Alle Rechnungen der {n}er-Reihe sitzen', ['n' => $reihe]), reihe_sitzt($stand, $reihe)];
    }
    $alle = true;
    for ($a = 1; $a <= 10 && $alle; $a++) {
        $alle = reihe_sitzt($stand, $a);
    }
    $liste[] = ['klein', '👑', t('Kleines 1×1'), t('Das ganze kleine 1×1 sitzt'), $alle];
    return array_map(fn($x) => ['id' => $x[0], 'symbol' => $x[1], 'titel' => $x[2], 'text' => $x[3], 'erreicht' => $x[4]], $liste);
}
