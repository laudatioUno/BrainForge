<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$q = &$_SESSION['quiz'];
if (!$q) {
    unset($_SESSION['quiz']);
    header('Location: index.php');
    exit;
}
$n = count($q['codes']);
$spielart = $q['spielart'] ?? 'flaggen';
$stufe = $q['stufe'] ?? '';
$umrisse = $spielart === 'umrisse';
$auswahl = $umrisse && $stufe !== 'schwer'; // Leicht/Mittel: 4 Antwortmöglichkeiten

// ---- Eingaben verarbeiten (danach immer Weiterleitung, damit Neuladen nichts doppelt schickt) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($q['token'], (string)($_POST['token'] ?? ''))) {
    $pos = $q['pos'];
    $aktion = $_POST['aktion'] ?? '';

    // Antwort prüfen – nur einmal pro Frage, danach ist die Lösung sichtbar
    if ($aktion === 'pruefen' && $pos < $n && !isset($q['antworten'][$pos])
        && (int)($_POST['pos'] ?? -1) === $pos) {
        $code = $q['codes'][$pos];
        if ($auswahl) {
            // Gewählt wird über die Position (0–3), damit im HTML kein Ländercode steht
            $nr = (int)($_POST['wahl'] ?? -1);
            $gewaehlt = $q['optionen'][$pos][$nr] ?? null;
            if ($gewaehlt !== null) {
                $q['antworten'][$pos] = [
                    'c' => $code, 'w' => $gewaehlt, 'l' => loesung_land($gewaehlt), 'h' => '',
                    'pl' => $gewaehlt === $code ? 'richtig' : 'falsch', 'ph' => '',
                ];
            }
        } elseif ($umrisse) {
            $el = mb_substr(trim((string)($_POST['land'] ?? '')), 0, 60);
            $q['antworten'][$pos] = ['c' => $code, 'l' => $el, 'h' => '', 'pl' => pruefe($el, $code, 0), 'ph' => ''];
        } else {
            $el = mb_substr(trim((string)($_POST['land'] ?? '')), 0, 60);
            $eh = mb_substr(trim((string)($_POST['haupt'] ?? '')), 0, 60);
            $q['antworten'][$pos] = [
                'c' => $code, 'l' => $el, 'h' => $eh,
                'pl' => pruefe($el, $code, 0),
                'ph' => pruefe($eh, $code, 1),
            ];
        }
    }

    // Zur nächsten Frage
    if ($aktion === 'weiter' && isset($q['antworten'][$pos]) && $pos < $n - 1) {
        $q['pos']++;
    }

    header('Location: quiz.php');
    exit;
}

$pos = $q['pos'];
$code = $q['codes'][$pos];
$antwort = $q['antworten'][$pos] ?? null;
$letzte = $pos === $n - 1;
$karte = $umrisse && in_array($code, KLEINSTAATEN, true);

// Punktestand bisher (Flaggen: 2 Punkte pro Frage, Umrisse: 1 Punkt)
$punkte = 0;
foreach ($q['antworten'] as $a) {
    $punkte += ($a['pl'] !== 'falsch' ? 1 : 0) + (!$umrisse && $a['ph'] !== 'falsch' ? 1 : 0);
}
$beantwortet = count($q['antworten']);
$max = $beantwortet * ($umrisse ? 1 : 2);


function rueckmeldung(string $titel, string $eingabe, string $status, string $loesung): string
{
    $cls = $status === 'richtig' ? 'ok' : ($status === 'tippfehler' ? 'tipp' : 'falsch');
    $icon = $status === 'richtig' ? '✓' : ($status === 'tippfehler' ? '≈' : '✗');
    $html = '<div class="feld ' . $cls . '"><span class="titel">' . h($titel) . '</span>'
        . '<span class="ein"><b>' . $icon . '</b> ' . ($eingabe === '' ? '<em>' . h(t('keine Antwort')) . '</em>' : '<i>' . h($eingabe) . '</i>') . '</span>';
    if ($status !== 'richtig') {
        $html .= '<span class="soll">' . ($status === 'tippfehler' ? t('Zählt als richtig. Korrekt heisst es:') . ' ' : t('Richtig:') . ' ') . h($loesung) . '</span>';
    }
    return $html . '</div>';
}

kopf($umrisse ? t('Umrisse') : t('Flaggen'));
?>
<header class="leiste">
  <h1><?= h($umrisse ? t('Umrisse') : t('Flaggen')) ?></h1>
  <span><?= h($q['name']) ?> · <?= h(kombi_name($spielart, $stufe, $q['modus'])) ?></span>
</header>

<div class="fortschritt">
  <span><?php $w = ['nr' => '<strong>' . ($pos + 1) . '</strong>', 'n' => $n]; ?><?= $umrisse ? t('Land {nr} von {n}', $w) : t('Flagge {nr} von {n}', $w) ?></span>
  <span><?= h(t('Punkte')) ?>: <strong><?= $punkte ?></strong> / <?= $max ?></span>
</div>
<div class="balken"><span style="width: <?= round(($pos + ($antwort ? 1 : 0)) / $n * 100, 1) ?>%"></span></div>

<section class="karte einzel">
<?php if (!$umrisse): ?>
  <img class="hauptbild" src="<?= h(flagge($code)) ?>" alt="<?= h(t('Flagge Nr. {nr}', ['nr' => $pos + 1])) ?>" draggable="false">
<?php else: ?>
  <div class="umriss-bild<?= $karte ? ' karte-bild' : '' ?>">
    <img src="<?= h(umriss($code)) ?>" alt="<?= h($karte ? t('Kartenausschnitt mit markiertem Kleinstaat') : t('Umriss eines europäischen Landes')) ?>" draggable="false">
    <?php if ($karte): ?><span class="karten-chip"><?= h(t('Kleinstaat · Lage auf der Karte')) ?></span><?php endif; ?>
  </div>
  <?php if (!$antwort): ?>
    <p class="frage-text"><?= h($karte ? t('Welcher Staat liegt im roten Kreis?') : ($stufe === 'mittel' ? t('Welche Flagge gehört zu diesem Land?') : t('Welches Land ist das?'))) ?></p>
  <?php endif; ?>
<?php endif; ?>

<?php if (!$antwort): ?>

  <?php if ($auswahl): ?>
    <form method="post" class="optionen-form">
      <input type="hidden" name="token" value="<?= h($q['token']) ?>">
      <input type="hidden" name="pos" value="<?= $pos ?>">
      <input type="hidden" name="aktion" value="pruefen">
      <div class="optionen <?= $stufe === 'mittel' ? 'flaggen-optionen' : '' ?>">
        <?php foreach ($q['optionen'][$pos] as $i => $opt): $buchstabe = chr(65 + $i); ?>
          <?php if ($stufe === 'mittel'): ?>
            <button type="submit" name="wahl" value="<?= $i ?>" class="option" aria-label="<?= h(t('Flagge {nr}', ['nr' => $buchstabe])) ?>">
              <span class="buchstabe"><?= $buchstabe ?></span>
              <img src="<?= h(flagge($opt)) ?>" alt="">
            </button>
          <?php else: ?>
            <button type="submit" name="wahl" value="<?= $i ?>" class="option">
              <span class="buchstabe"><?= $buchstabe ?></span><?= h(loesung_land($opt)) ?>
            </button>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <p class="hinweis"><?= h(t('Antippen genügt – die Auswahl wird sofort geprüft.')) ?></p>
    </form>

  <?php elseif ($umrisse): ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="token" value="<?= h($q['token']) ?>">
      <input type="hidden" name="pos" value="<?= $pos ?>">
      <input type="hidden" name="aktion" value="pruefen">
      <label><?= h(t('Land')) ?>
        <input name="land" id="land" autofocus autocapitalize="words" spellcheck="false" autocorrect="off">
      </label>
      <button type="submit"><?= h(t('Prüfen')) ?></button>
    </form>

  <?php else: ?>
    <form method="post" autocomplete="off" id="frage">
      <input type="hidden" name="token" value="<?= h($q['token']) ?>">
      <input type="hidden" name="pos" value="<?= $pos ?>">
      <input type="hidden" name="aktion" value="pruefen">
      <label><?= h(t('Land')) ?>
        <input name="land" id="land" autofocus autocapitalize="words" spellcheck="false" autocorrect="off">
      </label>
      <label><?= h(t('Hauptstadt')) ?>
        <input name="haupt" id="haupt" autocapitalize="words" spellcheck="false" autocorrect="off">
      </label>
      <button type="submit"><?= h(t('Prüfen')) ?></button>
    </form>
    <script>
    // Enter im Feld "Land" springt zur Hauptstadt, Enter in "Hauptstadt" prüft
    document.getElementById('land').addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); document.getElementById('haupt').focus(); }
    });
    </script>
  <?php endif; ?>

<?php else: ?>

  <?php if ($umrisse): ?>
    <?php
      $ok = $antwort['pl'] !== 'falsch';
      $ungenau = $antwort['pl'] === 'tippfehler';
    ?>
    <p class="urteil <?= $ok ? 'ok' : 'falsch' ?>">
      <?= h($ok ? ($ungenau ? t('Richtig! Achte noch auf die Schreibweise.') : t('Richtig, super!')) : t('Leider falsch.')) ?>
    </p>

    <?php if ($auswahl): ?>
      <div class="optionen <?= $stufe === 'mittel' ? 'flaggen-optionen' : '' ?>">
        <?php foreach ($q['optionen'][$pos] as $i => $opt):
          $istRichtig = $opt === $code;
          $istGewaehlt = $opt === $antwort['w'];
          $cls = $istRichtig ? 'opt-ok' : ($istGewaehlt ? 'opt-falsch' : 'opt-aus');
          $zusatz = $istRichtig ? ($istGewaehlt ? t('richtig') . ' · ' . t('deine Wahl') : t('richtig')) : ($istGewaehlt ? t('deine Wahl') : '');
        ?>
          <div class="option <?= $cls ?>">
            <?php if ($istRichtig || $istGewaehlt): ?>
              <span class="marke" aria-hidden="true"><?= $istRichtig ? '✓' : '✗' ?></span>
            <?php else: ?>
              <span class="buchstabe"><?= chr(65 + $i) ?></span>
            <?php endif; ?>
            <?php if ($stufe === 'mittel'): ?>
              <img src="<?= h(flagge($opt)) ?>" alt="">
            <?php endif; ?>
            <span class="opt-text">
              <span class="opt-name"><?= h(loesung_land($opt)) ?></span>
              <?php if ($zusatz): ?><span class="opt-zusatz"><?= h($zusatz) ?></span><?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <?= rueckmeldung(t('Land'), $antwort['l'], $antwort['pl'], loesung_land($code)) ?>
    <?php endif; ?>

    <p class="info-zeile"><?= t('Gesucht war {land} – Hauptstadt {haupt}.', ['land' => '<strong>' . h(loesung_land($code)) . '</strong>', 'haupt' => h(loesung_haupt($code))]) ?></p>

  <?php else: ?>
    <?php
      $alles = $antwort['pl'] !== 'falsch' && $antwort['ph'] !== 'falsch';
      $nichts = $antwort['pl'] === 'falsch' && $antwort['ph'] === 'falsch';
      $ungenau = $antwort['pl'] === 'tippfehler' || $antwort['ph'] === 'tippfehler';
    ?>
    <p class="urteil <?= $alles ? 'ok' : ($nichts ? 'falsch' : 'halb') ?>">
      <?= h($alles ? ($ungenau ? t('Richtig! Achte noch auf die Schreibweise.') : t('Richtig, super!')) : ($nichts ? t('Leider falsch.') : t('Teilweise richtig.'))) ?>
    </p>
    <?= rueckmeldung(t('Land'), $antwort['l'], $antwort['pl'], loesung_land($code)) ?>
    <?= rueckmeldung(t('Hauptstadt'), $antwort['h'], $antwort['ph'], loesung_haupt($code)) ?>
  <?php endif; ?>

  <?php if ($letzte): ?>
    <form method="post" action="auswertung.php">
      <input type="hidden" name="token" value="<?= h($q['token']) ?>">
      <button type="submit" autofocus><?= h(t('Zur Auswertung')) ?></button>
    </form>
  <?php else: ?>
    <form method="post">
      <input type="hidden" name="token" value="<?= h($q['token']) ?>">
      <input type="hidden" name="aktion" value="weiter">
      <button type="submit" autofocus><?= h($umrisse ? t('Nächstes Land') : t('Nächste Flagge')) ?> →</button>
    </form>
  <?php endif; ?>

<?php endif; ?>
</section>

<p class="klein"><a href="index.php" onclick="return confirm(<?= h(json_encode(t('Quiz abbrechen? Es wird nichts gespeichert.'), JSON_UNESCAPED_UNICODE)) ?>)"><?= h(t('Quiz abbrechen')) ?></a></p>
<?php fuss();
