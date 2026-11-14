<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

[$spielart, $stufe, $modus] = kombi_aus($_GET);
if ($spielart === 'einmaleins' && $modus === 'noetig') {
    $modus = 'blitz'; // «Nur was nötig ist» hat keine Rangliste
}
$filter = trim((string)($_GET['name'] ?? ''));
$kombi = kombi_name($spielart, $stufe, $modus);

$liste = rangliste($spielart, $stufe, $modus);
if ($filter !== '') {
    // Filter blendet nur aus – der Rang bleibt der in der gesamten Liste
    $liste = array_values(array_filter($liste, fn($z) => mb_stripos($z['name'], $filter) !== false));
}

/** Link auf diese Seite mit geänderten Parametern. */
function link_zu(array $aenderung): string
{
    global $spielart, $stufe, $modus, $filter;
    $p = array_merge(['spielart' => $spielart, 'stufe' => $stufe, 'modus' => $modus, 'name' => $filter], $aenderung);
    if ($p['spielart'] === 'flaggen') {
        unset($p['stufe']);
    }
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
}

// ---- CSV-Export ----
if (isset($_GET['csv'])) {
    $datei = 'brainforge_' . $spielart . ($stufe ? '-' . $stufe : '') . '_' . $modus . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $datei . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel die Umlaute korrekt zeigt
    fputcsv($out, [t('Rang'), t('Zeit'), t('Name'), t('Spielart'), t('Stufe'), t('Umfang'), t('Fragen'), t('Länder richtig'), t('Hauptstädte richtig'), t('Punkte'), t('Max. Punkte'), t('Prozent'), t('Dauer (s)')], ';');
    foreach ($liste as $z) {
        fputcsv($out, [$z['rang'], $z['zeit'], $z['name'], ['umrisse' => t('Umrisse'), 'einmaleins' => t('1×1')][$z['spielart']] ?? t('Flaggen'),
            isset(STUFEN[$z['stufe']]) ? t(STUFEN[$z['stufe']]) : ($z['spielart'] === 'einmaleins' ? t('bis {n}', ['n' => (int)$z['stufe']]) : ''),
            modus_name($z['modus']), $z['anzahl'], $z['land_richtig'],
            $z['spielart'] === 'flaggen' ? $z['haupt_richtig'] : '',
            $z['punkte'], $z['max_punkte'], str_replace('.', ',', (string)$z['prozent']), $z['dauer_sek']], ';');
    }
    exit;
}

// Anzahl Resultate je Spielart, Stufe und Umfang (für die Zahlen in den Reitern).
// Gezählt wird nur, was in einer Rangliste steht: «Nur was nötig ist» beim 1×1 hat keine.
$zahl = ['flaggen' => 0, 'umrisse' => 0, 'einmaleins' => 0];
$zahlStufe = [];
$modi = $spielart === 'einmaleins' ? ['alle', 'blitz'] : ['kurz', 'komplett'];
$zahlModus = array_fill_keys($modi, 0);
$zahlArt = ['alle' => 0, 'blitz' => 0];   // 1×1: Runden je Art (alle Bereiche)
$zahlBereich = [];                        // 1×1: Runden je Bereich in der gewählten Art
foreach (db()->query("SELECT spielart, stufe, modus, COUNT(*) AS n FROM resultate
    WHERE NOT (spielart = 'einmaleins' AND modus NOT IN ('alle', 'blitz')) GROUP BY spielart, stufe, modus") as $g) {
    $zahl[$g['spielart']] = ($zahl[$g['spielart']] ?? 0) + (int)$g['n'];
    if ($g['spielart'] === $spielart) {
        $zahlStufe[$g['stufe']] = ($zahlStufe[$g['stufe']] ?? 0) + (int)$g['n'];
    }
    if ($g['spielart'] === $spielart && $g['stufe'] === $stufe) {
        $zahlModus[$g['modus']] = (int)$g['n'];
    }
    if ($g['spielart'] === 'einmaleins') {
        $zahlArt[$g['modus']] += (int)$g['n'];
        if ($g['modus'] === $modus) {
            $zahlBereich[$g['stufe']] = (int)$g['n'];
        }
    }
}

kopf(t('Ergebnisse'));
?>
<header class="leiste">
  <h1><?= h(t('Ergebnisse')) ?></h1>
  <a class="knopf" href="index.php"><?= h(t('Quiz starten')) ?></a>
</header>

<nav class="tabs" aria-label="<?= h(t('Spielart')) ?>">
  <a href="<?= h(link_zu(['spielart' => 'flaggen'])) ?>" class="<?= $spielart === 'flaggen' ? 'aktiv' : '' ?>"><?= h(t('Flaggen')) ?> <span><?= $zahl['flaggen'] ?></span></a>
  <a href="<?= h(link_zu(['spielart' => 'umrisse', 'stufe' => 'mittel', 'modus' => 'kurz'])) ?>" class="<?= $spielart === 'umrisse' ? 'aktiv' : '' ?>"><?= h(t('Umrisse')) ?> <span><?= $zahl['umrisse'] ?></span></a>
  <a href="<?= h(link_zu(['spielart' => 'einmaleins', 'stufe' => (string)EINMALEINS_BIS[0], 'modus' => 'blitz'])) ?>" class="<?= $spielart === 'einmaleins' ? 'aktiv' : '' ?>"><?= h(t('1×1')) ?> <span><?= $zahl['einmaleins'] ?></span></a>
</nav>

<?php if ($spielart === 'einmaleins'): ?>
<?php
// 1×1: zuerst die Art (Blitz / Alle), darunter der Bereich
$arten = [
    'blitz' => [t('Blitz'), t('60 Sekunden – wer schafft am meisten?'), '<path d="M13 2L4 14h7l-1 8 9-12h-7z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>'],
    'alle' => [t('Alle'), t('Jede Aufgabe einmal – Quote, dann Zeit'), '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>'],
];
?>
<nav class="art-karten" aria-label="<?= h(t('Art der Rangliste')) ?>">
  <?php foreach ($arten as $m => [$titel, $text, $icon]): ?>
    <a href="<?= h(link_zu(['modus' => $m])) ?>" class="art-karte<?= $modus === $m ? ' aktiv' : '' ?>"<?= $modus === $m ? ' aria-current="true"' : '' ?>>
      <span class="art-icon"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><?= $icon ?></svg></span>
      <span class="art-text">
        <span><strong><?= h($titel) ?></strong> <span class="art-zahl"><?= h(t('{n} Runden', ['n' => $zahlArt[$m]])) ?></span></span>
        <small><?= h($text) ?></small>
      </span>
    </a>
  <?php endforeach; ?>
</nav>

<section class="karte rangliste-karte">
  <div class="rl-kopf">
    <h2><?= h($arten[$modus][0] . ' · ' . t('bis {n}', ['n' => (int)$stufe])) ?></h2>
    <nav class="pillen" aria-label="<?= h(t('Bereich')) ?>">
      <?php foreach (EINMALEINS_BIS as $n): $s = (string)$n; ?>
        <a href="<?= h(link_zu(['stufe' => $s])) ?>" class="<?= $stufe === $s ? 'aktiv' : '' ?>"<?= $stufe === $s ? ' aria-current="true"' : '' ?>><?= h(t('bis {n}', ['n' => $n])) ?> <span><?= $zahlBereich[$s] ?? 0 ?></span></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <?php if (!$liste && $filter === ''): ?>
    <div class="rl-leer">
      <strong><?= h(t('Noch niemand in dieser Rangliste.')) ?></strong>
      <span class="leise"><?= h($modus === 'blitz'
          ? t('Starte eine Blitz-Runde bis {n} und sei die oder der Erste.', ['n' => (int)$stufe])
          : t('Rechne alle {anzahl} Aufgaben bis {n} und sei die oder der Erste.', ['anzahl' => paare_anzahl((int)$stufe), 'n' => (int)$stufe])) ?></span>
      <a class="knopf" href="index.php?spielart=einmaleins&amp;stufe=<?= h($stufe) ?>&amp;modus=<?= h($modus) ?>"><?= h(t('{art} bis {n} spielen', ['art' => $arten[$modus][0], 'n' => (int)$stufe])) ?></a>
    </div>
  <?php else: ?>
    <div class="tabelle-wrap"><?php rangliste_tabelle($liste, $spielart); ?></div>
  <?php endif; ?>
  <div class="rl-fuss">
    <p class="klein"><?= h($modus === 'blitz' ? t('Sortiert nach Anzahl richtig, bei Gleichstand zählt die bessere Quote.') : t('Sortiert nach Quote, bei Gleichstand zählt die kürzere Zeit.')) ?></p>
    <form method="get" class="filter">
      <input type="hidden" name="spielart" value="einmaleins">
      <input type="hidden" name="stufe" value="<?= h($stufe) ?>">
      <input type="hidden" name="modus" value="<?= h($modus) ?>">
      <input name="name" placeholder="<?= h(t('Nach Name suchen')) ?>" aria-label="<?= h(t('Nach Name suchen')) ?>" value="<?= h($filter) ?>">
      <button type="submit"><?= h(t('Suchen')) ?></button>
      <?php if ($filter !== ''): ?><a class="sekundaer" href="<?= h(link_zu(['name' => ''])) ?>"><?= h(t('Alle zeigen')) ?></a><?php endif; ?>
      <a class="sekundaer" href="<?= h(link_zu(['csv' => 1])) ?>"><?= h(t('CSV-Export')) ?></a>
    </form>
  </div>
</section>
<p class="klein hinweis-info"><?= h(t('«Nur was nötig ist» hat keine Rangliste – dort bekommt jedes Kind andere Aufgaben. Diese Runden siehst du in deiner Statistik.')) ?></p>
<?php else: ?>
<div class="karte auswahl-leiste">
  <?php if ($spielart === 'umrisse'): ?>
  <div class="segment-gruppe">
    <span class="segment-titel"><?= h(t('Stufe')) ?></span>
    <div class="segment" role="group" aria-label="<?= h(t('Stufe')) ?>">
      <?php foreach (STUFEN as $s => $t): ?>
        <a href="<?= h(link_zu(['stufe' => $s])) ?>" class="<?= $stufe === $s ? 'aktiv' : '' ?>"<?= $stufe === $s ? ' aria-current="true"' : '' ?>><?= h(t($t)) ?> <span><?= $zahlStufe[$s] ?? 0 ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="segment-gruppe">
    <span class="segment-titel"><?= h(t('Umfang')) ?></span>
    <div class="segment" role="group" aria-label="<?= h(t('Umfang')) ?>">
      <?php foreach ($modi as $m): ?>
        <a href="<?= h(link_zu(['modus' => $m])) ?>" class="<?= $modus === $m ? 'aktiv' : '' ?>"<?= $modus === $m ? ' aria-current="true"' : '' ?>><?= modus_name($m) ?> <span><?= $zahlModus[$m] ?></span></a>
      <?php endforeach; ?>
    </div>
  </div>
  <form method="get" class="filter">
    <input type="hidden" name="spielart" value="<?= h($spielart) ?>">
    <?php if ($stufe !== ''): ?><input type="hidden" name="stufe" value="<?= h($stufe) ?>"><?php endif; ?>
    <input type="hidden" name="modus" value="<?= h($modus) ?>">
    <input name="name" placeholder="<?= h(t('Nach Name suchen')) ?>" aria-label="<?= h(t('Nach Name suchen')) ?>" value="<?= h($filter) ?>">
    <button type="submit"><?= h(t('Suchen')) ?></button>
    <?php if ($filter !== ''): ?><a class="sekundaer" href="<?= h(link_zu(['name' => ''])) ?>"><?= h(t('Alle zeigen')) ?></a><?php endif; ?>
    <a class="sekundaer" href="<?= h(link_zu(['csv' => 1])) ?>"><?= h(t('CSV-Export')) ?></a>
  </form>
</div>

<h2 class="zwischentitel"><?= h(t('Rangliste {kombi}', ['kombi' => $kombi])) ?></h2>
<div class="karte tabelle-wrap">
  <?php rangliste_tabelle($liste, $spielart); ?>
</div>
<p class="klein"><?= h(t('Jede Kombination aus Spielart, Stufe und Umfang hat eine eigene Rangliste. Sortiert nach Prozent, bei Gleichstand zählt die schnellere Zeit.')) ?></p>
<?php endif; ?>
<?php fuss();
