<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

[$spielart, $stufe, $modus] = kombi_aus($_GET);
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
    if ($p['spielart'] !== 'umrisse') {
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
        fputcsv($out, [$z['rang'], $z['zeit'], $z['name'], $z['spielart'] === 'umrisse' ? t('Umrisse') : t('Flaggen'),
            isset(STUFEN[$z['stufe']]) ? t(STUFEN[$z['stufe']]) : '', modus_name($z['modus']), $z['anzahl'], $z['land_richtig'],
            $z['spielart'] === 'umrisse' ? '' : $z['haupt_richtig'],
            $z['punkte'], $z['max_punkte'], str_replace('.', ',', (string)$z['prozent']), $z['dauer_sek']], ';');
    }
    exit;
}

// Anzahl Resultate je Spielart, Stufe und Umfang (für die Zahlen in den Reitern)
$zahl = ['flaggen' => 0, 'umrisse' => 0];
$zahlStufe = [];
$zahlModus = ['kurz' => 0, 'komplett' => 0];
foreach (db()->query('SELECT spielart, stufe, modus, COUNT(*) AS n FROM resultate GROUP BY spielart, stufe, modus') as $g) {
    $zahl[$g['spielart']] = ($zahl[$g['spielart']] ?? 0) + (int)$g['n'];
    if ($g['spielart'] === 'umrisse') {
        $zahlStufe[$g['stufe']] = ($zahlStufe[$g['stufe']] ?? 0) + (int)$g['n'];
    }
    if ($g['spielart'] === $spielart && $g['stufe'] === $stufe) {
        $zahlModus[$g['modus']] = (int)$g['n'];
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
  <a href="<?= h(link_zu(['spielart' => 'umrisse', 'stufe' => $stufe ?: 'mittel'])) ?>" class="<?= $spielart === 'umrisse' ? 'aktiv' : '' ?>"><?= h(t('Umrisse')) ?> <span><?= $zahl['umrisse'] ?></span></a>
</nav>

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
      <?php foreach (['kurz', 'komplett'] as $m): ?>
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
<?php fuss();
