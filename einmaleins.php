<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Eine 1×1-Runde. Kurz: 20 Aufgaben, die Zeit zählt. Blitz: so viele wie möglich in 60 Sekunden.
 * Eine falsche Rechnung kommt drei Aufgaben später nochmals (bei Kurz als Wiederholung, die
 * nicht zählt; bei Blitz als normale Aufgabe). Mit Profil wird jede Antwort im Lernstand gemerkt.
 */
$r = &$_SESSION['rechnen'];
if (!$r) {
    unset($_SESSION['rechnen']);
    header('Location: index.php');
    exit;
}
$blitz = $r['modus'] === 'blitz';

/** Zählt eine Antwort für das Ergebnis? (Kurz: Wiederholungen nicht) */
function zaehlt(array $antwort, bool $blitz): bool
{
    return $blitz || empty($antwort['w']);
}

/** Speichert die fertige Runde und leitet zur Auswertung. */
function runde_speichern(array $r): void
{
    $blitz = $r['modus'] === 'blitz';
    $gezaehlt = array_values(array_filter($r['antworten'], fn($x) => zaehlt($x, $blitz)));
    unset($_SESSION['rechnen']);
    if (!$gezaehlt) {
        header('Location: index.php');
        exit;
    }
    $anzahl = count($gezaehlt);
    $richtig = count(array_filter($gezaehlt, fn($x) => $x['ok']));
    $dauer = $blitz ? BLITZ_SEKUNDEN : max(1, time() - $r['start']);
    db()->prepare('INSERT INTO resultate
        (zeit, name, profil_id, spielart, stufe, modus, anzahl, land_richtig, haupt_richtig, punkte, max_punkte, prozent, dauer_sek, details)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([date('Y-m-d H:i:s'), $r['name'], $r['profil_id'], 'einmaleins', (string)$r['bis'], $r['modus'],
            $anzahl, $richtig, 0, $richtig, $anzahl, round($richtig / $anzahl * 100, 1), $dauer,
            json_encode($r['antworten'], JSON_UNESCAPED_UNICODE)]);
    $id = (int)db()->lastInsertId();
    $_SESSION['meine'][] = $id;
    header('Location: auswertung.php?id=' . $id);
    exit;
}

$zeitUm = $blitz && microtime(true) >= $r['ende'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($r['token'], (string)($_POST['token'] ?? ''))) {
    $aktion = $_POST['aktion'] ?? '';
    $pos = $r['pos'];
    // Blitz: Antworten bis 2 Sekunden nach Ablauf zählen noch (Übertragungszeit)
    $nochGueltig = !$blitz || microtime(true) <= $r['ende'] + 2;
    if ($aktion === 'antwort' && (int)($_POST['pos'] ?? -1) === $pos && isset($r['aufgaben'][$pos]) && $nochGueltig) {
        $eingabe = rechnung_eingabe((string)($_POST['ergebnis'] ?? ''));
        if ($eingabe !== null) {
            $auf = $r['aufgaben'][$pos];
            $ok = $eingabe === $auf['a'] * $auf['b'];
            $ms = (int)round((microtime(true) - $r['seit']) * 1000);
            $r['antworten'][] = ['a' => $auf['a'], 'b' => $auf['b'], 'e' => $eingabe, 'ok' => $ok, 'ms' => $ms, 'w' => $auf['w']];
            if ($r['profil_id'] !== null) {
                rechnung_merken((int)$r['profil_id'], $auf['a'], $auf['b'], $ok, $ms);
            }
            if (!$ok) {
                // drei Aufgaben später nochmals (höchstens zweimal dieselbe Rechnung wiederholen)
                $schluessel = $auf['a'] . '×' . $auf['b'];
                $r['wiederholt'][$schluessel] = ($r['wiederholt'][$schluessel] ?? 0) + 1;
                if ($r['wiederholt'][$schluessel] <= 2) {
                    array_splice($r['aufgaben'], min($pos + 4, count($r['aufgaben'])), 0, [['a' => $auf['a'], 'b' => $auf['b'], 'w' => 1]]);
                }
            }
            $r['letzte'] = ['a' => $auf['a'], 'b' => $auf['b'], 'e' => $eingabe, 'ok' => $ok, 'nochmals' => !$ok && ($r['wiederholt'][$auf['a'] . '×' . $auf['b']] ?? 0) <= 2];
            $r['pos']++;
            if ($blitz && $r['pos'] >= count($r['aufgaben']) - 5) {
                // Blitz: Nachschub, damit die Aufgaben nie ausgehen
                $stand = $r['profil_id'] !== null ? lernstand((int)$r['profil_id']) : [];
                array_push($r['aufgaben'], ...rechnungen_ziehen($r['bis'], 30, $stand, $r['profil_id'] !== null));
            }
            $r['seit'] = microtime(true);
        }
    }
    $fertig = $blitz ? microtime(true) >= $r['ende'] : $r['pos'] >= count($r['aufgaben']);
    if ($fertig && ($aktion === 'antwort' || $aktion === 'fertig')) {
        runde_speichern($r);
    }
    header('Location: einmaleins.php');
    exit;
}

if ($zeitUm || (!$blitz && $r['pos'] >= count($r['aufgaben']))) {
    runde_speichern($r);
}

$pos = $r['pos'];
$auf = $r['aufgaben'][$pos];
$r['seit'] = $r['seit_pos'] === $pos ? $r['seit'] : microtime(true);
$r['seit_pos'] = $pos;
$letzte = $r['letzte'];
$gezaehlt = array_filter($r['antworten'], fn($x) => zaehlt($x, $blitz));
$richtig = count(array_filter($gezaehlt, fn($x) => $x['ok']));
// Kurz: Nummer der Aufgabe ohne Wiederholungen
$nummer = 1 + count(array_filter(array_slice($r['aufgaben'], 0, $pos), fn($x) => empty($x['w'])));
$rest = $blitz ? max(0, (int)ceil($r['ende'] - microtime(true))) : 0;

kopf(t('1×1'));
?>
<header class="leiste">
  <h1><?= h(t('1×1')) ?></h1>
  <span><?= h($r['name']) ?> · <?= h(kombi_name('einmaleins', (string)$r['bis'], $r['modus'])) ?></span>
</header>

<div class="fortschritt">
  <?php if ($blitz): ?>
    <span><?= h(t('Noch')) ?> <strong id="uhr" data-ende="<?= (int)round(($r['ende'] - microtime(true)) * 1000) ?>"><?= sprintf('%d:%02d', intdiv($rest, 60), $rest % 60) ?></strong></span>
    <span><?= h(t('Richtig')) ?>: <strong><?= $richtig ?></strong></span>
  <?php else: ?>
    <span><?= t('Aufgabe {nr} von {n}', ['nr' => '<strong>' . min($nummer, EINMALEINS_KURZ) . '</strong>', 'n' => EINMALEINS_KURZ]) ?></span>
    <span><?= h(t('Richtig')) ?>: <strong><?= $richtig ?></strong> / <?= count($gezaehlt) ?></span>
  <?php endif; ?>
</div>
<div class="balken"><span style="width: <?= $blitz ? round($rest / BLITZ_SEKUNDEN * 100, 1) : round(count($gezaehlt) / EINMALEINS_KURZ * 100, 1) ?>%"></span></div>

<section class="karte einzel rechnen">
  <?php if ($letzte): ?>
    <p class="rueck <?= $letzte['ok'] ? 'ok' : 'falsch' ?>" role="status">
      <?php if ($letzte['ok']): ?>
        <b><?= h(t('Richtig:')) ?></b> <?= (int)$letzte['a'] ?> × <?= (int)$letzte['b'] ?> = <?= (int)$letzte['a'] * (int)$letzte['b'] ?>
      <?php else: ?>
        <b><?= h(t('Leider falsch:')) ?></b> <?= (int)$letzte['a'] ?> × <?= (int)$letzte['b'] ?> = <strong><?= (int)$letzte['a'] * (int)$letzte['b'] ?></strong>
        <span class="leise">(<?= h(t('du: {zahl}', ['zahl' => (int)$letzte['e']])) ?>)<?= $letzte['nochmals'] ? ' · ' . h(t('kommt gleich nochmals')) : '' ?></span>
      <?php endif; ?>
    </p>
  <?php endif; ?>

  <form method="post" autocomplete="off" class="rechnung-form" id="rechnung">
    <input type="hidden" name="token" value="<?= h($r['token']) ?>">
    <input type="hidden" name="pos" value="<?= $pos ?>">
    <input type="hidden" name="aktion" value="antwort">
    <?php if (!empty($auf['w'])): ?><span class="nochmals-chip"><?= h(t('Nochmals')) ?></span><?php endif; ?>
    <label class="rechnung" for="ergebnis">
      <span><?= (int)$auf['a'] ?> × <?= (int)$auf['b'] ?> =</span>
      <input id="ergebnis" name="ergebnis" inputmode="numeric" pattern="[0-9]*" maxlength="4" required autofocus
             enterkeyhint="go" spellcheck="false" aria-label="<?= h(t('Ergebnis von {a} mal {b}', ['a' => (int)$auf['a'], 'b' => (int)$auf['b']])) ?>">
    </label>
    <button type="submit"><?= h(t('Prüfen')) ?></button>
  </form>
  <?php if ($blitz): ?>
    <form method="post" id="fertig">
      <input type="hidden" name="token" value="<?= h($r['token']) ?>">
      <input type="hidden" name="aktion" value="fertig">
    </form>
  <?php endif; ?>
</section>

<p class="klein"><a href="index.php" onclick="return confirm(<?= h(json_encode(t('Runde abbrechen? Es wird nichts gespeichert.'), JSON_UNESCAPED_UNICODE)) ?>)"><?= h(t('Runde abbrechen')) ?></a></p>
<script>
(function () {
  var feld = document.getElementById('ergebnis');
  // Nur Ziffern; Fokus bleibt im Feld, auch nach einem Tipp daneben
  feld.addEventListener('input', function () { feld.value = feld.value.replace(/\D/g, ''); });
  feld.focus();
  var uhr = document.getElementById('uhr');
  if (!uhr) return;
  var ende = Date.now() + parseInt(uhr.getAttribute('data-ende'), 10);
  var balken = document.querySelector('.balken span');
  (function tick() {
    var rest = Math.max(0, ende - Date.now()), s = Math.ceil(rest / 1000);
    uhr.textContent = Math.floor(s / 60) + ':' + ('0' + s % 60).slice(-2);
    balken.style.width = (rest / <?= BLITZ_SEKUNDEN * 1000 ?> * 100) + '%';
    if (rest <= 0) {
      // Zeit um: eine angefangene Antwort noch abschicken, sonst die Runde beenden
      if (feld.value !== '') { document.getElementById('rechnung').submit(); } else { document.getElementById('fertig').submit(); }
      return;
    }
    setTimeout(tick, 200);
  })();
})();
</script>
<?php fuss();
