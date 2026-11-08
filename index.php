<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$fehler = '';
// Zuletzt gewählte Kombination vorauswählen
[$spielart, $stufe, $modus] = $_SESSION['letzte_kombi'] ?? ['flaggen', '', 'kurz'];
if (isset($_GET['spielart'])) {
    [$spielart, $stufe, $modus] = kombi_aus($_GET, [$spielart, $stufe, $modus]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profil = profil();
    if ($profil !== null) {
        $name = $profil['name'];
    } else {
        $name = trim((string)($_POST['name'] ?? ''));
        $name = mb_substr(preg_replace('/\s+/u', ' ', $name) ?? '', 0, 40);
    }
    $eingabe = $_POST;
    if (($eingabe['spielart'] ?? '') === 'einmaleins') {
        // eigene Feldnamen, weil die Formulargruppen der Länder gleichzeitig im Formular stehen
        $eingabe['stufe'] = EINMALEINS_BIS[(int)($eingabe['bereich'] ?? 0)] ?? EINMALEINS_BIS[0];
        $eingabe['modus'] = $eingabe['tempo'] ?? 'noetig';
    }
    [$spielart, $stufe, $modus] = kombi_aus($eingabe);

    if ($name === '') {
        $fehler = t('Bitte gib deinen Namen ein.');
    } elseif ($spielart === 'einmaleins') {
        $bis = (int)$stufe;
        $mitProfil = $profil !== null;
        $stand = $mitProfil ? lernstand((int)$profil['id']) : [];
        $auffrischen = false;
        if ($modus === 'alle') {
            $aufgaben = rechnungen_alle($bis);
        } elseif ($modus === 'blitz') {
            $aufgaben = rechnungen_ziehen($bis, 40, $stand, $mitProfil);
        } elseif ($mitProfil) {
            [$aufgaben, $auffrischen] = rechnungen_noetig($bis, $stand);
        } else {
            // Gäste: ohne Lernstand 20 gemischte Aufgaben
            $aufgaben = rechnungen_ziehen($bis, EINMALEINS_NOETIG_MAX, [], false);
        }
        $_SESSION['rechnen'] = [
            'token'     => bin2hex(random_bytes(16)),
            'name'      => $name,
            'profil_id' => $mitProfil ? (int)$profil['id'] : null,
            'bis'       => $bis,
            'modus'     => $modus,
            'aufgaben'  => $aufgaben,
            'gesamt'    => count($aufgaben),
            'auffrischen' => $auffrischen,
            'antworten' => [],
            'wiederholt' => [],
            'letzte'    => null,
            'pos'       => 0,
            'start'     => time(),
            'ende'      => microtime(true) + BLITZ_SEKUNDEN,
            'seit'      => microtime(true),
            'seit_pos'  => -1,
        ];
        if ($profil === null) {
            $_SESSION['letzter_name'] = $name;
        }
        $_SESSION['letzte_kombi'] = [$spielart, $stufe, $modus];
        header('Location: einmaleins.php');
        exit;
    } else {
        $codes = array_keys(LAENDER);
        shuffle($codes);
        if ($modus === 'kurz') {
            $codes = array_slice($codes, 0, ANZAHL_KURZ);
        }
        // Antwortmöglichkeiten gleich beim Start festlegen (Neuladen ändert sie nicht)
        $optionen = [];
        if ($spielart === 'umrisse' && $stufe !== 'schwer') {
            foreach ($codes as $i => $c) {
                $optionen[$i] = antwortmoeglichkeiten($c, $stufe);
            }
        }
        $_SESSION['quiz'] = [
            'token'    => bin2hex(random_bytes(16)),
            'name'     => $name,
            'profil_id' => $profil !== null ? (int)$profil['id'] : null,
            'spielart' => $spielart,
            'stufe'    => $stufe,
            'modus'    => $modus,
            'codes'    => $codes,
            'optionen' => $optionen,
            'start'    => time(),
            'pos'      => 0,      // aktuelle Frage
            'antworten' => [],    // geprüfte Antworten je Position
        ];
        if ($profil === null) {
            $_SESSION['letzter_name'] = $name;
        }
        $_SESSION['letzte_kombi'] = [$spielart, $stufe, $modus];
        header('Location: quiz.php');
        exit;
    }
}

// Bestenliste: welche Kombination wird gezeigt? (flaggen, leicht/mittel/schwer, e10/e12/e15)
$bl = $_GET['bl'] ?? match ($spielart) { 'umrisse' => $stufe, 'einmaleins' => 'e' . $stufe, default => 'flaggen' };
$einmaleinsListen = array_map(fn($n) => 'e' . $n, EINMALEINS_BIS);
if ($bl !== 'flaggen' && !isset(STUFEN[$bl]) && !in_array($bl, $einmaleinsListen, true)) {
    $bl = 'flaggen';
}
$blSpielart = $bl === 'flaggen' ? 'flaggen' : (isset(STUFEN[$bl]) ? 'umrisse' : 'einmaleins');
$blStufe = $bl === 'flaggen' ? '' : ($blSpielart === 'umrisse' ? $bl : substr($bl, 1));
$blModi = $blSpielart === 'einmaleins' ? ['alle', 'blitz'] : ['kurz', 'komplett'];
$bereich = $spielart === 'einmaleins' ? (int)array_search((int)$stufe, EINMALEINS_BIS, true) : 0;
$tempo = $spielart === 'einmaleins' ? $modus : 'noetig';
// Mit Profil: wie viele Aufgaben heute nötig sind (je Bereich) und das Tagesziel
$ich = profil();
$noetig = [];
$ziel = null;
if ($ich !== null) {
    $meinStand = lernstand((int)$ich['id']);
    foreach (EINMALEINS_BIS as $n) {
        $noetig[$n] = noetig_anzahl($n, $meinStand);
    }
    $ziel = tagesziel_stand($ich);
}
if ($spielart === 'einmaleins') {
    $modus = 'kurz'; // Umfang der Länder-Quiz
}

$alle = count(LAENDER);
$stufeGewaehlt = isset(STUFEN[$stufe]) ? $stufe : 'mittel';
kopf(APP_NAME);
?>
<header class="hero">
  <h1><?= h(t('Üben mit BrainForge')) ?></h1>
  <p><?= h(t('Flaggen und Umrisse erkennen, Länder und Hauptstädte lernen – und das 1×1 trainieren.')) ?></p>
</header>

<form method="post" class="karte start" id="startform">
  <?php if ($fehler): ?><p class="meldung"><?= h($fehler) ?></p><?php endif; ?>
  <?php if ($ich !== null): ?>
  <p class="spielt-als"><?= avatar_svg($ich['avatar'], 36) ?> <span><?= h(t('Du spielst als')) ?> <strong><?= h($ich['name']) ?></strong></span></p>
  <?php else: ?>
  <label for="name"><?= h(t('Dein Name')) ?> <span class="leise">· <?= h(t('als Gast')) ?></span></label>
  <input id="name" name="name" maxlength="40" required autocomplete="off"
         value="<?= h($_POST['name'] ?? $_SESSION['letzter_name'] ?? '') ?>">
  <small class="leise"><?= h(t('Mit einem Profil merkt sich BrainForge deine Runden.')) ?> <a href="profil-neu.php"><?= h(t('Profil erstellen')) ?></a></small>
  <?php endif; ?>

  <fieldset>
    <legend><?= h(t('Was willst du üben?')) ?></legend>
    <div class="wahl-drei spielarten">
      <label class="wahl spielart">
        <input type="radio" name="spielart" value="flaggen" <?= $spielart === 'flaggen' ? 'checked' : '' ?>>
        <img src="<?= h(flagge('ch')) ?>" alt="">
        <span><strong><?= h(t('Flaggen')) ?></strong><small><?= h(t('Flagge sehen, Land und Hauptstadt schreiben')) ?></small></span>
      </label>
      <label class="wahl spielart">
        <input type="radio" name="spielart" value="umrisse" <?= $spielart === 'umrisse' ? 'checked' : '' ?>>
        <img src="<?= h(umriss('it')) ?>" alt="">
        <span><strong><?= h(t('Umrisse')) ?></strong><small><?= h(t('Land an seiner Form erkennen')) ?></small></span>
      </label>
      <label class="wahl spielart">
        <input type="radio" name="spielart" value="einmaleins" <?= $spielart === 'einmaleins' ? 'checked' : '' ?>>
        <span class="rechnen-icon" aria-hidden="true">7×8</span>
        <span><strong><?= h(t('1×1')) ?> <em class="neu"><?= h(t('NEU')) ?></em></strong><small><?= h(t('Malrechnen im Kopf, schnell und sicher')) ?></small></span>
      </label>
    </div>
  </fieldset>

  <fieldset id="einmaleins-wahl">
    <legend><?= h(t('1×1')) ?></legend>
    <div class="bereich">
      <label for="bereich"><?= h(t('Bereich')) ?>: <strong id="bereich-wert"><?= h(t('bis {n}', ['n' => EINMALEINS_BIS[$bereich]])) ?></strong></label>
      <input type="range" id="bereich" name="bereich" min="0" max="<?= count(EINMALEINS_BIS) - 1 ?>" step="1" value="<?= $bereich ?>"
             list="bereich-marken" data-werte="<?= h(json_encode(array_map(fn($n) => t('bis {n}', ['n' => $n]), EINMALEINS_BIS), JSON_UNESCAPED_UNICODE)) ?>">
      <datalist id="bereich-marken"><?php foreach (EINMALEINS_BIS as $i => $n): ?><option value="<?= $i ?>" label="<?= $n ?>"></option><?php endforeach; ?></datalist>
      <div class="bereich-marken" aria-hidden="true"><?php foreach (EINMALEINS_BIS as $n): ?><span><?= $n ?>×</span><?php endforeach; ?></div>
      <small class="leise"><?= h(t('Bis 10 ist das kleine 1×1, bis 12 oder 15 wird es knifflig.')) ?></small>
    </div>
    <?php
    // Texte je Bereich; das Skript unten tauscht sie beim Schieben aus
    $texteAlle = $texteNoetig = [];
    foreach (EINMALEINS_BIS as $n) {
        $texteAlle[] = t('alle {n} Aufgaben einmal, die Zeit zählt', ['n' => paare_anzahl($n)]);
        if ($ich === null) {
            $texteNoetig[] = t('{n} gemischte Aufgaben (mit Profil: genau das, was du noch übst)', ['n' => EINMALEINS_NOETIG_MAX]);
        } elseif ($noetig[$n] === 0) {
            $texteNoetig[] = t('heute ist nichts fällig – {n} zum Auffrischen', ['n' => EINMALEINS_AUFFRISCHEN]);
        } else {
            $texteNoetig[] = t('heute {n} Aufgaben, die du noch übst', ['n' => $noetig[$n]]);
        }
    }
    ?>
    <div class="wahl-modi">
      <label class="wahl">
        <input type="radio" name="tempo" value="noetig" <?= $tempo === 'noetig' ? 'checked' : '' ?>>
        <span><strong><?= h(t('Nur was nötig ist')) ?></strong> · <span class="modus-text" data-texte="<?= h(json_encode($texteNoetig, JSON_UNESCAPED_UNICODE)) ?>"><?= h($texteNoetig[$bereich]) ?></span></span>
      </label>
      <label class="wahl">
        <input type="radio" name="tempo" value="alle" <?= $tempo === 'alle' ? 'checked' : '' ?>>
        <span><strong><?= h(t('Alle')) ?></strong> · <span class="modus-text" data-texte="<?= h(json_encode($texteAlle, JSON_UNESCAPED_UNICODE)) ?>"><?= h($texteAlle[$bereich]) ?></span></span>
      </label>
      <label class="wahl">
        <input type="radio" name="tempo" value="blitz" <?= $tempo === 'blitz' ? 'checked' : '' ?>>
        <span><strong><?= h(t('Blitz')) ?></strong> · <?= h(t('so viele wie möglich in {n} Sekunden', ['n' => BLITZ_SEKUNDEN])) ?></span>
      </label>
    </div>
    <?php if ($ziel !== null): [$heute, $soll] = $ziel; ?>
      <div class="tagesziel<?= $heute >= $soll ? ' erreicht' : '' ?>">
        <span><?= $heute >= $soll ? '🎯 ' . h(t('Tagesziel erreicht!')) : h(t('Tagesziel')) ?></span>
        <span class="ziel-balken" role="progressbar" aria-valuemin="0" aria-valuemax="<?= $soll ?>" aria-valuenow="<?= min($heute, $soll) ?>"
              aria-label="<?= h(t('Tagesziel')) ?>"><span style="width: <?= round(min(1, $heute / $soll) * 100) ?>%"></span></span>
        <span class="ziel-zahl"><?= h(t('{n} von {ziel} Aufgaben', ['n' => $heute, 'ziel' => $soll])) ?></span>
      </div>
    <?php endif; ?>
  </fieldset>

  <fieldset id="stufen">
    <legend><?= h(t('Stufe')) ?></legend>
    <div class="wahl-drei">
      <?php
      $beschrieb = [
          'leicht' => t('Land aus 4 Namen auswählen'),
          'mittel' => t('Passende Flagge aus 4 auswählen'),
          'schwer' => t('Land selbst schreiben'),
      ];
      $punkte = ['leicht' => 1, 'mittel' => 2, 'schwer' => 3];
      foreach (STUFEN as $s => $titel): ?>
        <label class="wahl stufe">
          <span class="stufe-kopf">
            <input type="radio" name="stufe" value="<?= $s ?>" <?= $stufeGewaehlt === $s ? 'checked' : '' ?>>
            <span class="punkte punkte-<?= $s ?>" aria-hidden="true"><?= str_repeat('<i class="an"></i>', $punkte[$s]) . str_repeat('<i></i>', 3 - $punkte[$s]) ?></span>
          </span>
          <strong><?= h(t($titel)) ?></strong>
          <small><?= h($beschrieb[$s]) ?></small>
        </label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset id="umfang">
    <legend><?= h(t('Umfang')) ?></legend>
    <div class="wahl-zwei">
      <label class="wahl">
        <input type="radio" name="modus" value="kurz" <?= $modus === 'kurz' ? 'checked' : '' ?>>
        <span><strong><?= h(t('Kurz')) ?></strong> · <?= h(t('{n} zufällige Länder', ['n' => ANZAHL_KURZ])) ?></span>
      </label>
      <label class="wahl">
        <input type="radio" name="modus" value="komplett" <?= $modus === 'komplett' ? 'checked' : '' ?>>
        <span><strong><?= h(t('Komplett')) ?></strong> · <?= h(t('alle {n} Länder', ['n' => $alle])) ?></span>
      </label>
    </div>
  </fieldset>

  <button type="submit"><?= h(t('Los geht\'s')) ?></button>
</form>
<p class="klein"><?= h(t('Tipp: Umlaute und Gross-/Kleinschreibung sind egal (ue statt ü geht auch).')) ?></p>
<script>
// Nur die Wahl zur gewählten Spielart zeigen (ohne JavaScript bleibt alles sichtbar)
(function () {
  var form = document.getElementById('startform');
  var bereich = document.getElementById('bereich'), wert = document.getElementById('bereich-wert');
  var texte = JSON.parse(bereich.getAttribute('data-werte'));
  function zeigen() {
    var art = (form.querySelector('input[name=spielart]:checked') || {}).value;
    document.getElementById('stufen').hidden = art !== 'umrisse';
    document.getElementById('umfang').hidden = art === 'einmaleins';
    document.getElementById('einmaleins-wahl').hidden = art !== 'einmaleins';
    wert.textContent = texte[bereich.value];
    form.querySelectorAll('.modus-text').forEach(function (el) { el.textContent = JSON.parse(el.getAttribute('data-texte'))[bereich.value]; });
  }
  form.addEventListener('change', zeigen);
  bereich.addEventListener('input', zeigen);
  zeigen();
})();
</script>

<section class="bestenliste" id="bestenliste">
  <div class="bl-kopf">
    <h2><?= h(t('Bestenliste')) ?></h2>
    <a class="sekundaer" href="ergebnisse.php?spielart=<?= $blSpielart ?>&amp;stufe=<?= h($blStufe) ?>"><?= h(t('Alle Ergebnisse')) ?> →</a>
  </div>
  <nav class="chips" aria-label="<?= h(t('Bestenliste wählen')) ?>">
    <?php
    $chips = ['flaggen' => t('Flaggen'), 'leicht' => t('Umrisse') . ' · ' . t('Leicht'), 'mittel' => t('Umrisse') . ' · ' . t('Mittel'), 'schwer' => t('Umrisse') . ' · ' . t('Schwer')];
    foreach (EINMALEINS_BIS as $n) {
        $chips['e' . $n] = t('1×1') . ' · ' . t('bis {n}', ['n' => $n]);
    }
    foreach ($chips as $k => $titel): ?>
      <a href="?bl=<?= $k ?>#bestenliste" class="<?= $bl === $k ? 'aktiv' : '' ?>"<?= $bl === $k ? ' aria-current="true"' : '' ?>><?= h($titel) ?></a>
    <?php endforeach; ?>
  </nav>
  <div class="bl-spalten">
  <?php foreach ($blModi as $m): $top = array_slice(rangliste($blSpielart, $blStufe, $m), 0, 5); ?>
    <div class="karte bl">
      <h3><?= modus_name($m) ?></h3>
      <?php if (!$top): ?>
        <p class="leer"><?= h(t('Noch keine Ergebnisse.')) ?></p>
      <?php else: ?>
        <ol>
        <?php foreach ($top as $z): ?>
          <li>
            <span class="rang"><?= ['', '🥇', '🥈', '🥉'][(int)$z['rang']] ?? (int)$z['rang'] . '.' ?></span>
            <span class="bl-name"><?= spieler_name($z) ?></span>
            <span class="bl-wert"><?= ist_blitz($blSpielart, $m)
                ? h(t('{n} richtig', ['n' => (int)$z['punkte']])) . ' · ' . number_format((float)$z['prozent'], 0, '.', '') . ' %'
                : number_format((float)$z['prozent'], 1, '.', '') . ' % · ' . h(dauer((int)$z['dauer_sek'])) ?></span>
          </li>
        <?php endforeach; ?>
        </ol>
      <?php endif; ?>
      <a href="ergebnisse.php?spielart=<?= $blSpielart ?>&amp;stufe=<?= h($blStufe) ?>&amp;modus=<?= $m ?>"><?= h(t('Ganze Rangliste')) ?></a>
    </div>
  <?php endforeach; ?>
  </div>
</section>
<?php fuss();
