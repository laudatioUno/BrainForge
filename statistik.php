<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// 1×1-Statistik eines Profils: Farbkarte, Abzeichen, Serie, Verlauf
$p = profil();
if ($p === null) {
    header('Location: profil-neu.php');
    exit;
}
$id = (int)$p['id'];
$stand = lernstand($id);
$runden = einmaleins_runden($id);
$serie = serie($id);
[$aktuell, $laengste] = $serie;
$abzeichen = abzeichen($id, $stand, $runden, $serie);
$erreicht = count(array_filter($abzeichen, fn($a) => $a['erreicht']));
usort($abzeichen, fn($x, $y) => $y['erreicht'] <=> $x['erreicht']); // erreichte zuerst, sonst in der Reihenfolge der Liste

// Grösse der Farbkarte: der grösste Bereich, der schon gespielt wurde (mindestens 10)
$gross = 10;
foreach ($runden as $r) {
    $gross = max($gross, (int)$r['stufe']);
}
foreach ($stand as $s) {
    $gross = max($gross, (int)$s['a'], (int)$s['b']);
}
$gross = min($gross, max(EINMALEINS_BIS));
$zaehler = ['sitzt' => 0, 'unsicher' => 0, 'ueben' => 0, 'neu' => 0];
for ($a = 1; $a <= $gross; $a++) {
    for ($b = 1; $b <= $gross; $b++) {
        $zaehler[rechnung_stand($stand[$a . '×' . $b] ?? null)]++;
    }
}
$verlauf = array_reverse(array_slice($runden, 0, 10));
$staende = [
    'sitzt' => t('sitzt'),
    'unsicher' => t('unsicher'),
    'ueben' => t('noch üben'),
    'neu' => t('noch nie gerechnet'),
];

kopf(t('Meine 1×1-Statistik'));
?>
<header class="leiste">
  <h1><?= h(t('Meine 1×1-Statistik')) ?></h1>
  <a class="knopf" href="index.php?spielart=einmaleins"><?= h(t('Jetzt üben')) ?></a>
</header>

<section class="karte stat-karte stat-kopf">
  <?= avatar_svg($p['avatar'], 56) ?>
  <dl class="profil-zahlen">
    <div><dt><?= h(t('Serie')) ?></dt><dd><?= $aktuell >= 1 ? '🔥 ' : '' ?><?= h(t('{n} Tage', ['n' => $aktuell])) ?></dd></div>
    <div><dt><?= h(t('Längste Serie')) ?></dt><dd><?= h(t('{n} Tage', ['n' => $laengste])) ?></dd></div>
    <div><dt><?= h(t('1×1-Runden')) ?></dt><dd><?= count($runden) ?></dd></div>
    <div><dt><?= h(t('Rechnungen, die sitzen')) ?></dt><dd><?= $zaehler['sitzt'] ?> / <?= $gross * $gross ?></dd></div>
  </dl>
  <?php if ($aktuell === 0): ?>
    <p class="leise"><?= h(t('Übe heute, dann startet deine Serie. Jeder Tag mit mindestens einer Runde zählt.')) ?></p>
  <?php endif; ?>
</section>

<h2 class="zwischentitel"><?= h(t('Deine 1×1-Tafel')) ?></h2>
<section class="karte stat-karte">
  <p class="leise"><?= h(t('Jedes Feld ist eine Rechnung. Was du oft richtig hast, wird grün; was du noch übst, kommt in den nächsten Runden öfter.')) ?></p>
  <div class="tafel-scroll">
    <table class="tafel" style="--n: <?= $gross ?>">
      <thead><tr><th scope="col"><span class="visually-hidden"><?= h(t('mal')) ?></span>×</th><?php for ($b = 1; $b <= $gross; $b++): ?><th scope="col"><?= $b ?></th><?php endfor; ?></tr></thead>
      <tbody>
      <?php for ($a = 1; $a <= $gross; $a++): ?>
        <tr><th scope="row"><?= $a ?></th>
        <?php for ($b = 1; $b <= $gross; $b++):
            $s = $stand[$a . '×' . $b] ?? null;
            $st = rechnung_stand($s);
            $titel = $a . ' × ' . $b . ' = ' . ($a * $b) . ' · ' . $staende[$st]
                . ($s ? ' · ' . t('{r}× richtig, {f}× falsch', ['r' => (int)$s['richtig'], 'f' => (int)$s['falsch']]) : '');
        ?><td class="st-<?= $st ?>" title="<?= h($titel) ?>"><span class="visually-hidden"><?= h($titel) ?></span><span aria-hidden="true"><?= $a * $b ?></span></td><?php endfor; ?>
        </tr>
      <?php endfor; ?>
      </tbody>
    </table>
  </div>
  <ul class="legende">
    <?php foreach ($staende as $k => $text): ?>
      <li><span class="punkt st-<?= $k ?>"></span><?= h($text) ?> <span class="leise">(<?= $zaehler[$k] ?>)</span></li>
    <?php endforeach; ?>
  </ul>
</section>

<h2 class="zwischentitel"><?= h(t('Abzeichen')) ?> <span class="leise">· <?= $erreicht ?> / <?= count($abzeichen) ?></span></h2>
<ul class="abzeichen">
  <?php foreach ($abzeichen as $z): ?>
    <li class="<?= $z['erreicht'] ? 'erreicht' : 'offen' ?>">
      <span class="symbol" aria-hidden="true"><?= h($z['symbol']) ?></span>
      <strong><?= h($z['titel']) ?></strong>
      <small><?= h($z['text']) ?></small>
      <span class="visually-hidden"><?= h($z['erreicht'] ? t('erreicht') : t('noch offen')) ?></span>
    </li>
  <?php endforeach; ?>
</ul>

<h2 class="zwischentitel"><?= h(t('Verlauf')) ?></h2>
<section class="karte stat-karte">
  <?php if (!$verlauf): ?>
    <p class="leer"><?= h(t('Noch keine 1×1-Runden. Los geht’s!')) ?></p>
  <?php else: ?>
    <p class="leise"><?= h(t('Deine letzten {n} Runden: Balken = Anteil richtig, dazu das Tempo pro Aufgabe.', ['n' => count($verlauf)])) ?></p>
    <ol class="verlauf">
      <?php foreach ($verlauf as $r):
          $tempo = (int)$r['anzahl'] > 0 ? (int)$r['dauer_sek'] / (int)$r['anzahl'] : 0;
      ?>
        <li>
          <a href="auswertung.php?id=<?= (int)$r['id'] ?>">
            <span class="v-datum"><?= h(date('d.m.', strtotime($r['zeit']))) ?></span>
            <span class="v-art"><?= h(modus_name($r['modus'])) ?> · <?= h(t('bis {n}', ['n' => (int)$r['stufe']])) ?></span>
            <span class="v-balken"><span style="width: <?= (float)$r['prozent'] ?>%"></span></span>
            <span class="v-wert"><?= (int)$r['punkte'] ?>/<?= (int)$r['anzahl'] ?> · <?= h(t('{s} s', ['s' => number_format($tempo, 1, '.', '')])) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>
</section>
<?php fuss();
