<?php
declare(strict_types=1);

// Diese Seite darf auch laufen, solange das Update noch fehlt.
const UPDATE_SEITE = true;
require __DIR__ . '/lib.php';

$fehler = '';
$sicherung = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && update_noetig()) {
    if (!hash_equals($_SESSION['update_token'] ?? '', (string)($_POST['token'] ?? ''))) {
        $fehler = t('Die Seite war zu lange offen. Bitte nochmals auf GO klicken.');
    } else {
        try {
            $sicherung = db_update();
            unset($_SESSION['update_token']);
        } catch (Throwable $e) {
            $fehler = t('Das Update hat nicht geklappt:') . ' ' . $e->getMessage();
        }
    }
}

if (!update_noetig() && $sicherung === null) {
    // Nichts zu tun (z. B. Seite später nochmals aufgerufen)
    header('Location: index.php');
    exit;
}

if (update_noetig()) {
    $_SESSION['update_token'] = $_SESSION['update_token'] ?? bin2hex(random_bytes(16));
    $anzahl = (int)db()->query('SELECT COUNT(*) FROM resultate')->fetchColumn();
    $schritte = update_schritte_offen();
}

kopf(t('Update'));
?>
<section class="karte start update">
<?php if ($sicherung !== null): ?>
  <h1><?= h(t('Update erledigt')) ?></h1>
  <p><?= h(t('Die Datenbank ist jetzt auf dem neuesten Stand. Alle bisherigen Resultate sind erhalten.')) ?></p>
  <?php if ($sicherung !== ''): ?>
    <p class="klein-links"><?= h(t('Sicherungskopie:')) ?> <code>data/<?= h($sicherung) ?></code></p>
  <?php endif; ?>
  <a class="knopf" href="index.php"><?= h(t('Weiter zur Startseite')) ?></a>
<?php else: ?>
  <h1><?= h(t('Update nötig')) ?></h1>
  <p><?= h(t('Die neue Version braucht ein paar Ergänzungen in der Datenbank. Das dauert nur einen Moment.')) ?></p>
  <ul class="update-liste">
    <?php foreach ($schritte as $text): ?><li><?= h(t('Neu: {text}', ['text' => t($text)])) ?></li><?php endforeach; ?>
    <li><?= h(t('Vorher wird automatisch eine Sicherungskopie angelegt.')) ?></li>
    <li><?= h(t('Die {n} bisherigen Resultate bleiben erhalten.', ['n' => $anzahl])) ?></li>
  </ul>
  <?php if ($fehler): ?><p class="meldung"><?= h($fehler) ?></p><?php endif; ?>
  <form method="post">
    <input type="hidden" name="token" value="<?= h($_SESSION['update_token']) ?>">
    <button type="submit">GO</button>
  </form>
<?php endif; ?>
</section>
<?php fuss();
