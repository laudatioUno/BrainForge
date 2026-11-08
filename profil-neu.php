<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

$fehler = '';
$name = spitzname((string)($_SESSION['letzter_name'] ?? ''));
$email = '';
$avatar = $_SESSION['neues_avatar'] ?? avatar_neu();

// Wohin es nach dem Erstellen weitergeht (nur eigene Seiten, z. B. zurück zur Auswertung)
if (isset($_GET['zurueck']) && preg_match('/^[a-z-]+\.php(\?[a-z]+=\d+)?$/', (string)$_GET['zurueck'])) {
    $_SESSION['nach_profil'] = (string)$_GET['zurueck'];
}

// Nach dem Erstellen: Link einmal anzeigen
if (isset($_GET['fertig']) && isset($_SESSION['neuer_link']) && profil() !== null) {
    $fertig = $_SESSION['neuer_link'];
} else {
    $fertig = null;
}

if ($fertig === null && profil() !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: profil.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = spitzname((string)($_POST['name'] ?? ''));
    $email = email_normal((string)($_POST['email'] ?? ''));
    $gesendet = (string)($_POST['avatar'] ?? '');
    $avatar = avatar_gueltig($gesendet) ? $gesendet : avatar_neu();

    if (!csrf_ok()) {
        $fehler = t('Die Seite war zu lange offen. Bitte nochmals versuchen.');
    } elseif (isset($_POST['wuerfeln'])) {
        $avatar = avatar_neu();
    } elseif ($name === '') {
        $fehler = t('Bitte gib einen Spitznamen ein.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fehler = t('Bitte gib eine gültige E-Mail-Adresse ein.');
    } elseif (!drossel('profil:' . client_ip(), 10, 3600)) {
        $fehler = t('Von hier wurden in letzter Zeit sehr viele Profile erstellt. Bitte später nochmals versuchen.');
    } else {
        [$id, $schluessel] = profil_erstellen($name, $email, $avatar);
        geraet_anmelden($id);
        $link = profil_link($schluessel);
        $mailFehler = links_mailen($email, [[$name, $link]], true);
        unset($_SESSION['neues_avatar']);
        $_SESSION['neuer_link'] = ['link' => $link, 'email' => $email, 'mail_ok' => $mailFehler === null];
        profil_nach_anmeldung($id);
        header('Location: profil-neu.php?fertig=1');
        exit;
    }
}
$_SESSION['neues_avatar'] = $avatar;

kopf(t('Profil erstellen'));

if ($fertig !== null):
    $p = profil();
    ?>
<section class="karte start profil-fertig">
  <div class="mitte"><?= avatar_svg($p['avatar'], 84) ?>
    <h1><?= h(t('Hallo {name}!', ['name' => $p['name']])) ?></h1>
    <p class="leise"><?= h(t('Dein Profil ist bereit. Auf diesem Gerät bleibst du angemeldet.')) ?></p>
  </div>

  <h2><?= h(t('Dein persönlicher Link')) ?></h2>
  <p class="leise"><?= h(t('Damit kommst du auf jedem Gerät direkt in dein Profil.')) ?>
    <?php if ($fertig['mail_ok']): ?>
      <?= h(t('Wir haben ihn auch an {email} geschickt.', ['email' => $fertig['email']])) ?>
    <?php endif; ?>
  </p>
  <?php if (!$fertig['mail_ok']): ?>
    <p class="meldung"><?= h(t('Die Mail konnte nicht verschickt werden. Speichere den Link jetzt als Lesezeichen oder kopiere ihn.')) ?></p>
  <?php endif; ?>
  <div class="link-feld">
    <input id="link" value="<?= h($fertig['link']) ?>" readonly aria-label="<?= h(t('Dein persönlicher Link')) ?>">
    <button type="button" id="kopieren"><?= h(t('Kopieren')) ?></button>
  </div>
  <p class="hinweis-warn"><?= h(t('Gib den Link niemandem weiter. Wer ihn hat, kann in deinem Profil üben.')) ?></p>

  <h2><?= h(t('Als App auf den Home-Bildschirm')) ?></h2>
  <ol class="schritte">
    <li><strong>iPhone / iPad:</strong> <?= h(t('In Safari auf «Teilen» tippen, dann «Zum Home-Bildschirm».')) ?></li>
    <li><strong>Android:</strong> <?= h(t('Im Chrome-Menü (⋮) «Zum Startbildschirm hinzufügen» wählen.')) ?></li>
  </ol>
  <p class="leise"><?= h(t('Das Symbol öffnet BrainForge, und du bist direkt angemeldet.')) ?></p>

  <a class="knopf" href="<?= h($_SESSION['nach_profil'] ?? 'index.php') ?>"><?= h(isset($_SESSION['nach_profil']) ? t('Weiter') : t('Los geht\'s')) ?></a>
</section>
<script>
document.getElementById('kopieren').addEventListener('click', function () {
  var feld = document.getElementById('link'), knopf = this;
  feld.select();
  (navigator.clipboard ? navigator.clipboard.writeText(feld.value) : Promise.reject()).catch(function () { document.execCommand('copy'); })
    .finally(function () { knopf.textContent = <?= json_encode(t('Kopiert ✓'), JSON_UNESCAPED_UNICODE) ?>; });
});
</script>
<?php
    unset($_SESSION['neuer_link'], $_SESSION['nach_profil']);
    fuss();
    exit;
endif;
?>
<form method="post" class="karte start profil-form" novalidate>
  <h1><?= h(t('Profil erstellen')) ?></h1>
  <p class="leise"><?= h(t('Damit sich BrainForge merkt, was du schon kannst.')) ?></p>
  <?php if ($fehler): ?><p class="meldung"><?= h($fehler) ?></p><?php endif; ?>
  <?= csrf_feld() ?>
  <input type="hidden" name="avatar" value="<?= h($avatar) ?>">

  <div class="avatar-wahl">
    <?= avatar_svg($avatar, 112, t('Dein Profil-Icon')) ?>
    <button type="submit" name="wuerfeln" value="1" class="sekundaer" formnovalidate><?= h(t('Neu würfeln')) ?></button>
  </div>

  <label for="name"><?= h(t('Spitzname')) ?></label>
  <input id="name" name="name" maxlength="30" autocomplete="nickname" value="<?= h($name) ?>">
  <small class="leise"><?= h(t('Erscheint in der Rangliste. Keinen ganzen Namen verwenden.')) ?></small>

  <label for="email"><?= h(t('E-Mail (z. B. von Mama oder Papa)')) ?></label>
  <input id="email" name="email" type="email" autocomplete="email" value="<?= h($email) ?>">
  <small class="leise"><?= h(t('Dorthin schicken wir deinen persönlichen Link. Mehrere Profile dürfen dieselbe Adresse haben.')) ?></small>

  <button type="submit"><?= h(t('Profil erstellen')) ?></button>
  <p class="klein"><?= h(t('Schon ein Profil?')) ?> <a href="link-vergessen.php"><?= h(t('Link vergessen?')) ?></a></p>
</form>
<?php fuss();
