<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// 1×1-Statistik eines Profils: Tagesziel, Farbkarte, Reihen, Bestwerte, Abzeichen, Serie, Verlauf
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
$ziel = tagesziel($p);
$abzeichen = abzeichen($id, $stand, $runden, $serie, $ziel);
$erreicht = count(array_filter($abzeichen, fn($a) => $a['erreicht']));
usort($abzeichen, fn($x, $y) => $y['erreicht'] <=> $x['erreicht']); // erreichte zuerst, sonst in der Reihenfolge der Liste

// Grösse der Farbkarte: der grösste Bereich, der schon gespielt wurde (mindestens 10)
$gross = 10;
foreach ($runden as $r) {
    $gross = max($gross, (int)$r['stufe']);
}
foreach ($stand as $s) {
    $gross = max($gross, (int)$s['b']);
}
$gross = min($gross, max(EINMALEINS_BIS));
$zaehler = ['sitzt' => 0, 'unsicher' => 0, 'ueben' => 0, 'neu' => 0];
// gezählt wird jede Aufgabe einmal (7 × 8 und 8 × 7 sind dieselbe)
for ($a = 1; $a <= $gross; $a++) {
    for ($b = $a; $b <= $gross; $b++) {
        $zaehler[rechnung_stand($stand[$a . '×' . $b] ?? null)]++;
    }
}
// Tagesziel: heute und die letzten 7 Tage
$proTag = aufgaben_pro_tag($id);
$heute = $proTag[date('Y-m-d')] ?? 0;
$zielTage = $ziel > 0 ? count(array_filter($proTag, fn($n) => $n >= $ziel)) : 0;
$woche = [];
for ($i = 6; $i >= 0; $i--) {
    $tag = date('Y-m-d', strtotime("-$i day"));
    $woche[$tag] = $proTag[$tag] ?? 0;
}
$wocheMax = max([$ziel, 1, ...array_values($woche)]);
$best = bestwerte($runden);
$wochentage = [t('So'), t('Mo'), t('Di'), t('Mi'), t('Do'), t('Fr'), t('Sa')];
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
    <div><dt><?= h(t('Rechnungen, die sitzen')) ?></dt><dd><?= $zaehler['sitzt'] ?> / <?= paare_anzahl($gross) ?></dd></div>
  </dl>
  <?php if ($aktuell === 0): ?>
    <p class="leise"><?= h(t('Übe heute, dann startet deine Serie. Jeder Tag mit mindestens einer Runde zählt.')) ?></p>
  <?php endif; ?>
</section>

<h2 class="zwischentitel"><?= h(t('Tagesziel')) ?></h2>
<section class="karte stat-karte">
  <?php if ($ziel === 0): ?>
    <p class="leise"><?= h(t('Kein Tagesziel gesetzt.')) ?> <a href="profil.php#tagesziel"><?= h(t('Tagesziel festlegen')) ?></a></p>
  <?php else: ?>
    <div class="tagesziel<?= $heute >= $ziel ? ' erreicht' : '' ?>">
      <span><?= $heute >= $ziel ? '🎯 ' . h(t('Tagesziel erreicht!')) : h(t('Heute')) ?></span>
      <span class="ziel-balken" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $ziel ?>" aria-valuenow="<?= min($heute, $ziel) ?>"
            aria-label="<?= h(t('Tagesziel')) ?>"><span style="width: <?= round(min(1, $heute / $ziel) * 100) ?>%"></span></span>
      <span class="ziel-zahl"><?= h(t('{n} von {ziel} Aufgaben', ['n' => $heute, 'ziel' => $ziel])) ?></span>
    </div>
    <ol class="woche" aria-label="<?= h(t('Die letzten 7 Tage')) ?>">
      <?php foreach ($woche as $tag => $n): ?>
        <li class="<?= $n >= $ziel ? 'erreicht' : '' ?>" title="<?= h(date('d.m.', strtotime($tag)) . ': ' . t('{n} Aufgaben', ['n' => $n])) ?>">
          <span class="w-saeule"><span style="height: <?= round(min(1, $n / $wocheMax) * 100) ?>%"></span><i style="bottom: <?= round($ziel / $wocheMax * 100) ?>%"></i></span>
          <span class="w-tag"><?= h($wochentage[(int)date('w', strtotime($tag))]) ?></span>
          <span class="visually-hidden"><?= h(t('{n} Aufgaben', ['n' => $n])) ?></span>
        </li>
      <?php endforeach; ?>
    </ol>
    <p class="leise"><?= h(t('Ziel: {ziel} Aufgaben pro Tag · an {n} Tagen erreicht.', ['ziel' => $ziel, 'n' => $zielTage])) ?> <a href="profil.php#tagesziel"><?= h(t('Ändern')) ?></a></p>
  <?php endif; ?>
</section>

<h2 class="zwischentitel"><?= h(t('Deine 1×1-Tafel')) ?></h2>
<section class="karte stat-karte">
  <p class="leise"><?= h(t('Jedes Feld ist eine Rechnung. Was du oft richtig hast, wird grün; was du noch übst, kommt in den nächsten Runden öfter.')) ?>
    <?= h(t('7 × 8 und 8 × 7 sind dieselbe Aufgabe.')) ?></p>
  <div class="tafel-scroll">
    <table class="tafel" style="--n: <?= $gross ?>">
      <thead><tr><th scope="col"><span class="visually-hidden"><?= h(t('mal')) ?></span>×</th><?php for ($b = 1; $b <= $gross; $b++): ?><th scope="col"><?= $b ?></th><?php endfor; ?></tr></thead>
      <tbody>
      <?php for ($a = 1; $a <= $gross; $a++): ?>
        <tr><th scope="row"><?= $a ?></th>
        <?php for ($b = 1; $b <= $gross; $b++):
            $s = stand_von($stand, $a, $b);
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

<h2 class="zwischentitel"><?= h(t('Reihen')) ?></h2>
<section class="karte stat-karte">
  <p class="leise"><?= h(t('Wie viele Aufgaben jeder Reihe schon sitzen (bis {n}).', ['n' => $gross])) ?></p>
  <ul class="reihen">
    <?php for ($n = 1; $n <= $gross; $n++): [$sitzt, $total] = reihe_fortschritt($stand, $n, $gross); ?>
      <li class="<?= $sitzt === $total ? 'fertig' : '' ?>">
        <span class="r-name"><?= h(t('{n}er-Reihe', ['n' => $n])) ?></span>
        <span class="r-balken" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $total ?>" aria-valuenow="<?= $sitzt ?>"
              aria-label="<?= h(t('{n}er-Reihe', ['n' => $n])) ?>"><span style="width: <?= round($sitzt / $total * 100) ?>%"></span></span>
        <span class="r-wert"><?= $sitzt === $total ? '✓' : $sitzt . '/' . $total ?></span>
      </li>
    <?php endfor; ?>
  </ul>
</section>

<h2 class="zwischentitel"><?= h(t('Bestwerte')) ?></h2>
<section class="karte stat-karte">
  <?php if (!$best): ?>
    <p class="leer"><?= h(t('Noch keine 1×1-Runden. Los geht’s!')) ?></p>
  <?php else: ?>
  <div class="tabelle-wrap">
  <table class="liste bestwerte">
    <thead><tr><th><?= h(t('Bereich')) ?></th><th>⚡ <?= h(t('Blitz')) ?></th><th>🧩 <?= h(t('Alle')) ?></th><th><?= h(t('Tempo')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($best as $bis => $b): ?>
      <tr>
        <th scope="row"><?= h(t('bis {n}', ['n' => $bis])) ?></th>
        <td><?= $b['blitz'] ? '<a href="auswertung.php?id=' . (int)$b['blitz']['id'] . '">' . h(t('{n} richtig', ['n' => (int)$b['blitz']['punkte']])) . '</a>' : '–' ?></td>
        <td><?= $b['alle'] ? '<a href="auswertung.php?id=' . (int)$b['alle']['id'] . '">' . number_format((float)$b['alle']['prozent'], 0, '.', '') . ' % · ' . h(dauer((int)$b['alle']['dauer_sek'])) . '</a>' : '–' ?></td>
        <td><?= $b['tempo'] !== null ? h(t('{s} s pro Aufgabe', ['s' => number_format($b['tempo'], 1, '.', '')])) : '–' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
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
