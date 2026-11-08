<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$p = profil();
if ($p === null) {
    header('Location: profil-neu.php');
    exit;
}
$meldung = '';
$fehler = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if (!csrf_ok()) {
        $fehler = t('Die Seite war zu lange offen. Bitte nochmals versuchen.');
    } elseif ($aktion === 'abmelden') {
        geraet_abmelden();
        header('Location: index.php');
        exit;
    } elseif ($aktion === 'tagesziel') {
        $ziel = (int)($_POST['tagesziel'] ?? TAGESZIEL_STANDARD);
        if (in_array($ziel, TAGESZIELE, true)) {
            db()->prepare('UPDATE profile SET tagesziel = ? WHERE id = ?')->execute([$ziel, $p['id']]);
        }
        header('Location: profil.php#tagesziel');
        exit;
    } elseif ($aktion === 'wuerfeln') {
        db()->prepare('UPDATE profile SET avatar = ? WHERE id = ?')->execute([avatar_neu(), $p['id']]);
        header('Location: profil.php');
        exit;
    } elseif ($aktion === 'neuer_link') {
        if (!drossel('neuer-link:' . $p['id'], 3, 3600)) {
            $fehler = t('Zu viele Versuche. Bitte in ein paar Minuten nochmals.');
        } else {
            $link = profil_link(profil_neuer_link((int)$p['id'], (int)$p['geraet_id']));
            $mailFehler = links_mailen($p['email'], [[$p['name'], $link]], false);
            $_SESSION['neuer_link'] = ['link' => $link, 'email' => $p['email'], 'mail_ok' => $mailFehler === null];
            header('Location: profil.php?link=1');
            exit;
        }
    }
}

$neu = isset($_GET['link']) ? ($_SESSION['neuer_link'] ?? null) : null;
unset($_SESSION['neuer_link']);

$st = db()->prepare('SELECT COUNT(*) AS n, MAX(zeit) AS zuletzt FROM resultate WHERE profil_id = ?');
$st->execute([$p['id']]);
$runden = $st->fetch();
$st = db()->prepare('SELECT COUNT(*) FROM geraete WHERE profil_id = ?');
$st->execute([$p['id']]);
$geraete = (int)$st->fetchColumn();
$st = db()->prepare('SELECT id, zeit, spielart, stufe, modus, prozent, dauer_sek FROM resultate WHERE profil_id = ? ORDER BY id DESC LIMIT 8');
$st->execute([$p['id']]);
$letzte = $st->fetchAll();

kopf(t('Mein Profil'));
?>
<section class="karte start profil-seite">
  <div class="profil-kopf">
    <?= avatar_svg($p['avatar'], 84, t('Dein Profil-Icon')) ?>
    <div>
      <h1><?= h($p['name']) ?></h1>
      <p class="leise"><?= h($p['email']) ?> · <?= h(t('dabei seit {datum}', ['datum' => date('d.m.Y', strtotime($p['erstellt']))])) ?></p>
    </div>
  </div>
  <?php if ($fehler): ?><p class="meldung"><?= h($fehler) ?></p><?php endif; ?>

  <?php if ($neu): ?>
    <div class="karte-innen">
      <h2><?= h(t('Dein neuer persönlicher Link')) ?></h2>
      <?php if ($neu['mail_ok']): ?>
        <p class="leise"><?= h(t('Wir haben ihn auch an {email} geschickt.', ['email' => $neu['email']])) ?></p>
      <?php else: ?>
        <p class="meldung"><?= h(t('Die Mail konnte nicht verschickt werden. Speichere den Link jetzt als Lesezeichen oder kopiere ihn.')) ?></p>
      <?php endif; ?>
      <div class="link-feld">
        <input id="link" value="<?= h($neu['link']) ?>" readonly aria-label="<?= h(t('Dein persönlicher Link')) ?>">
        <button type="button" id="kopieren"><?= h(t('Kopieren')) ?></button>
      </div>
    </div>
    <script>
    document.getElementById('kopieren').addEventListener('click', function () {
      var feld = document.getElementById('link'), knopf = this;
      feld.select();
      (navigator.clipboard ? navigator.clipboard.writeText(feld.value) : Promise.reject()).catch(function () { document.execCommand('copy'); })
        .finally(function () { knopf.textContent = <?= json_encode(t('Kopiert ✓'), JSON_UNESCAPED_UNICODE) ?>; });
    });
    </script>
  <?php endif; ?>

  <dl class="profil-zahlen">
    <div><dt><?= h(t('Runden')) ?></dt><dd><?= (int)$runden['n'] ?></dd></div>
    <div><dt><?= h(t('Zuletzt gespielt')) ?></dt><dd><?= $runden['zuletzt'] ? h(date('d.m.Y', strtotime($runden['zuletzt']))) : '–' ?></dd></div>
    <div><dt><?= h(t('Angemeldete Geräte')) ?></dt><dd><?= $geraete ?></dd></div>
  </dl>

  <p><a class="knopf" href="statistik.php"><?= h(t('Meine 1×1-Statistik')) ?></a></p>

  <?php if ($letzte): ?>
    <h2><?= h(t('Letzte Runden')) ?></h2>
    <ul class="runden-liste">
      <?php foreach ($letzte as $r): ?>
        <li><a href="auswertung.php?id=<?= (int)$r['id'] ?>">
          <span><?= h(kombi_name($r['spielart'], $r['stufe'], $r['modus'])) ?></span>
          <span class="leise"><?= h(date('d.m.Y', strtotime($r['zeit']))) ?> · <?= number_format((float)$r['prozent'], 1, '.', '') ?> % · <?= h(dauer((int)$r['dauer_sek'])) ?></span>
        </a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <h2><?= h(t('Einstellungen')) ?></h2>
  <form method="post" class="profil-aktion" id="tagesziel">
    <?= csrf_feld() ?><input type="hidden" name="aktion" value="tagesziel">
    <label for="ziel-wahl"><?= h(t('Tagesziel beim 1×1')) ?></label>
    <span class="ziel-wahl">
      <select id="ziel-wahl" name="tagesziel">
        <?php foreach (TAGESZIELE as $z): ?>
          <option value="<?= $z ?>" <?= tagesziel($p) === $z ? 'selected' : '' ?>><?= h($z === 0 ? t('kein Ziel') : t('{n} Aufgaben pro Tag', ['n' => $z])) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="sekundaer"><?= h(t('Speichern')) ?></button>
    </span>
  </form>
  <form method="post" class="profil-aktion">
    <?= csrf_feld() ?><input type="hidden" name="aktion" value="wuerfeln">
    <span><?= h(t('Gefällt dir dein Icon nicht mehr?')) ?></span>
    <button type="submit" class="sekundaer"><?= h(t('Neu würfeln')) ?></button>
  </form>
  <form method="post" class="profil-aktion">
    <?= csrf_feld() ?><input type="hidden" name="aktion" value="neuer_link">
    <span><?= h(t('Neuer Link: Der alte wird ungültig, andere Geräte werden abgemeldet. Dieses Gerät bleibt angemeldet.')) ?></span>
    <button type="submit" class="sekundaer"><?= h(t('Neuen Link anfordern')) ?></button>
  </form>
  <form method="post" class="profil-aktion">
    <?= csrf_feld() ?><input type="hidden" name="aktion" value="abmelden">
    <span><?= h(t('Auf diesem Gerät abmelden. Mit deinem Link kommst du jederzeit wieder rein.')) ?></span>
    <button type="submit" class="sekundaer"><?= h(t('Abmelden')) ?></button>
  </form>
</section>
<?php fuss();
