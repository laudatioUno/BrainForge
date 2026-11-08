<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Selbst einen neuen persönlichen Link anfordern. Die Antwort verrät nie, ob es die Adresse gibt.
$gesendet = false;
$fehler = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = email_normal((string)($_POST['email'] ?? ''));
    if (!csrf_ok()) {
        $fehler = t('Die Seite war zu lange offen. Bitte nochmals versuchen.');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fehler = t('Bitte gib eine gültige E-Mail-Adresse ein.');
    } elseif (!drossel('vergessen-ip:' . client_ip(), 10, 3600)) {
        $fehler = t('Zu viele Versuche. Bitte in ein paar Minuten nochmals.');
    } else {
        // Höchstens 3 Mails pro Adresse und Stunde; sonst still nichts tun
        if (drossel('vergessen-mail:' . $email, 3, 3600)) {
            $st = db()->prepare('SELECT id, name FROM profile WHERE email = ? ORDER BY id');
            $st->execute([$email]);
            $links = [];
            foreach ($st->fetchAll() as $p) {
                $links[] = [$p['name'], profil_link(profil_neuer_link((int)$p['id']))];
            }
            if ($links) {
                links_mailen($email, $links, false);
            }
        }
        $gesendet = true;
    }
}

kopf(t('Link vergessen'));
?>
<section class="karte start">
  <h1><?= h(t('Link vergessen?')) ?></h1>
  <?php if ($gesendet): ?>
    <p><?= h(t('Falls es zu {email} ein Profil gibt, ist jetzt eine Mail mit einem neuen Link unterwegs.', ['email' => $email])) ?></p>
    <p class="leise"><?= h(t('Der alte Link funktioniert nicht mehr, und alle Geräte wurden abgemeldet. Öffne den neuen Link auf jedem Gerät, das du brauchst.')) ?></p>
    <p class="leise"><?= h(t('Keine Mail? Schau im Spam-Ordner nach.')) ?></p>
    <a class="knopf" href="index.php"><?= h(t('Zur Startseite')) ?></a>
  <?php else: ?>
    <p class="leise"><?= h(t('Gib die E-Mail-Adresse deines Profils ein. Wir schicken dir einen neuen persönlichen Link.')) ?></p>
    <p class="hinweis-warn"><?= h(t('Der alte Link wird dabei ungültig, und alle Geräte werden abgemeldet.')) ?></p>
    <?php if ($fehler): ?><p class="meldung"><?= h($fehler) ?></p><?php endif; ?>
    <form method="post" class="profil-form">
      <?= csrf_feld() ?>
      <label for="email"><?= h(t('E-Mail')) ?></label>
      <input id="email" name="email" type="email" required autocomplete="email" value="<?= h($email) ?>">
      <button type="submit"><?= h(t('Neuen Link schicken')) ?></button>
    </form>
  <?php endif; ?>
</section>
<?php fuss();
