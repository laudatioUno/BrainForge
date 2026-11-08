<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

/*
 * Admin-Bereich: Profile verwalten und Mailversand einrichten.
 * Das Passwort steht in config.php (ADMIN_PASSWORT).
 */
$gesperrt = !defined('ADMIN_PASSWORT') || ADMIN_PASSWORT === '' || ADMIN_PASSWORT === 'bitte-aendern';
$fehler = '';
$meldung = $_SESSION['admin_meldung'] ?? '';
$linkAnzeige = $_SESSION['admin_link'] ?? null;
unset($_SESSION['admin_meldung'], $_SESSION['admin_link']);

function zurueck(string $meldung, string $anker = ''): never
{
    $_SESSION['admin_meldung'] = $meldung;
    header('Location: admin.php' . ($anker !== '' ? '#' . $anker : ''));
    exit;
}

if (!$gesperrt && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $aktion = (string)($_POST['aktion'] ?? '');
    if (!csrf_ok()) {
        $fehler = t('Die Seite war zu lange offen. Bitte nochmals versuchen.');
    } elseif ($aktion === 'login') {
        if (!drossel('admin:' . client_ip(), 5, 900)) {
            $fehler = t('Zu viele Versuche. Bitte in ein paar Minuten nochmals.');
        } elseif (hash_equals(ADMIN_PASSWORT, (string)($_POST['passwort'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: admin.php');
            exit;
        } else {
            $fehler = t('Falsches Passwort.');
        }
    } elseif (empty($_SESSION['admin'])) {
        $fehler = t('Bitte zuerst anmelden.');
    } elseif ($aktion === 'logout') {
        unset($_SESSION['admin']);
        header('Location: admin.php');
        exit;
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $st = db()->prepare('SELECT * FROM profile WHERE id = ?');
        $st->execute([$id]);
        $profil = $st->fetch() ?: null;

        if ($aktion === 'link' && $profil) {
            $link = profil_link(profil_neuer_link($id));
            $mailFehler = links_mailen($profil['email'], [[$profil['name'], $link]], false);
            $_SESSION['admin_link'] = ['name' => $profil['name'], 'link' => $link];
            zurueck($mailFehler === null
                ? t('Neuer Link für «{name}» an {email} geschickt.', ['name' => $profil['name'], 'email' => $profil['email']])
                : t('Neuer Link für «{name}» erstellt, aber die Mail ging nicht raus:', ['name' => $profil['name']]) . ' ' . $mailFehler, 'profile');
        } elseif ($aktion === 'umbenennen' && $profil) {
            $name = spitzname((string)($_POST['name'] ?? ''));
            if ($name === '') {
                zurueck(t('Bitte gib einen Spitznamen ein.'), 'profile');
            }
            db()->prepare('UPDATE profile SET name = ? WHERE id = ?')->execute([$name, $id]);
            db()->prepare('UPDATE resultate SET name = ? WHERE profil_id = ?')->execute([$name, $id]);
            zurueck(t('«{alt}» heisst jetzt «{neu}».', ['alt' => $profil['name'], 'neu' => $name]), 'profile');
        } elseif ($aktion === 'loeschen' && $profil) {
            $pdo = db();
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM resultate WHERE profil_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM geraete WHERE profil_id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM profile WHERE id = ?')->execute([$id]);
            $pdo->commit();
            zurueck(t('Profil «{name}» und seine Runden sind gelöscht.', ['name' => $profil['name']]), 'profile');
        } elseif ($aktion === 'mail_speichern' || $aktion === 'mail_test') {
            $neu = [
                'mail_art' => ($_POST['mail_art'] ?? '') === 'php' ? 'php' : 'smtp',
                'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
                'smtp_port' => max(1, min(65535, (int)($_POST['smtp_port'] ?? 587))),
                'smtp_sicherheit' => in_array($_POST['smtp_sicherheit'] ?? '', ['starttls', 'ssl', 'keine'], true) ? $_POST['smtp_sicherheit'] : 'starttls',
                'smtp_benutzer' => trim((string)($_POST['smtp_benutzer'] ?? '')),
                'absender_mail' => trim((string)($_POST['absender_mail'] ?? '')),
                'absender_name' => trim(str_replace(["\r", "\n"], '', (string)($_POST['absender_name'] ?? ''))),
                'basis_url' => trim((string)($_POST['basis_url'] ?? '')),
            ];
            // Passwort nur ändern, wenn eines eingegeben wurde
            if ((string)($_POST['smtp_passwort'] ?? '') !== '') {
                $neu['smtp_passwort'] = (string)$_POST['smtp_passwort'];
            }
            if (isset($_POST['passwort_loeschen'])) {
                $neu['smtp_passwort'] = '';
            }
            if ($neu['absender_mail'] !== '' && !filter_var($neu['absender_mail'], FILTER_VALIDATE_EMAIL)) {
                zurueck(t('Die Absender-Adresse ist ungültig.'), 'mail');
            }
            if ($neu['basis_url'] !== '' && !preg_match('#^https?://[^\s/]+#', $neu['basis_url'])) {
                zurueck(t('Die Adresse der Seite muss mit http:// oder https:// beginnen.'), 'mail');
            }
            if (!einstellungen_speichern($neu)) {
                zurueck(t('Die Einstellungen konnten nicht gespeichert werden (Ordner data/ beschreibbar?).'), 'mail');
            }
            if ($aktion === 'mail_test') {
                header('Location: admin.php?test=' . rawurlencode(trim((string)($_POST['test_an'] ?? ''))) . '#mail');
                exit;
            }
            zurueck(t('Mail-Einstellungen gespeichert.'), 'mail');
        } else {
            zurueck(t('Dieses Profil gibt es nicht mehr.'), 'profile');
        }
    }
}

// Testmail (nach dem Speichern, damit die neuen Einstellungen gelten)
$testErgebnis = null;
if (!$gesperrt && !empty($_SESSION['admin']) && isset($_GET['test'])) {
    $an = (string)$_GET['test'];
    $fehlerText = mail_senden($an, t('BrainForge: Testmail'), t('Der Mailversand von BrainForge funktioniert.') . "\n\n" . basis_url() . "\n");
    $testErgebnis = $fehlerText === null ? [true, t('Testmail an {email} verschickt.', ['email' => $an])] : [false, $fehlerText];
}

kopf(t('Admin'));

if ($gesperrt): ?>
<section class="karte start">
  <h1><?= h(t('Admin')) ?></h1>
  <p class="meldung"><?= h(t('Der Admin-Bereich ist gesperrt, bis in config.php ein eigenes ADMIN_PASSWORT steht.')) ?></p>
</section>
<?php fuss(); exit; endif;

if (empty($_SESSION['admin'])): ?>
<form method="post" class="karte start profil-form">
  <h1><?= h(t('Admin')) ?></h1>
  <?php if ($fehler): ?><p class="meldung"><?= h($fehler) ?></p><?php endif; ?>
  <?= csrf_feld() ?><input type="hidden" name="aktion" value="login">
  <label for="passwort"><?= h(t('Passwort')) ?></label>
  <input id="passwort" name="passwort" type="password" autocomplete="current-password" required autofocus>
  <button type="submit"><?= h(t('Anmelden')) ?></button>
</form>
<?php fuss(); exit; endif;

$profile = db()->query('SELECT p.*,
    (SELECT COUNT(*) FROM resultate r WHERE r.profil_id = p.id) AS runden,
    (SELECT COUNT(*) FROM geraete g WHERE g.profil_id = p.id) AS geraete
    FROM profile p ORDER BY COALESCE(p.letzte_anmeldung, p.erstellt) DESC')->fetchAll();
$gaeste = (int)db()->query('SELECT COUNT(*) FROM resultate WHERE profil_id IS NULL')->fetchColumn();
$e = einstellungen();
$datum = fn(?string $z) => $z ? date('d.m.Y H:i', strtotime($z)) : '–';
?>
<header class="leiste">
  <h1><?= h(t('Admin')) ?></h1>
  <form method="post"><?= csrf_feld() ?><input type="hidden" name="aktion" value="logout">
    <button type="submit" class="sekundaer"><?= h(t('Abmelden')) ?></button></form>
</header>
<?php if ($meldung): ?><p class="hinweis-ok"><?= h($meldung) ?></p><?php endif; ?>
<?php if ($fehler): ?><p class="meldung"><?= h($fehler) ?></p><?php endif; ?>
<?php if (!mail_eingerichtet()): ?>
  <p class="meldung"><?= h(t('Der Mailversand ist noch nicht eingerichtet. Ohne ihn bekommen neue Profile ihren Link nur auf dem Bildschirm.')) ?> <a href="#mail"><?= h(t('Jetzt einrichten')) ?></a></p>
<?php endif; ?>

<section class="karte" id="profile">
  <h2><?= h(t('Profile')) ?> <span class="leise">(<?= count($profile) ?>)</span></h2>
  <p class="leise"><?= h(t('{n} Runden wurden als Gast gespielt.', ['n' => $gaeste])) ?></p>
  <?php if ($linkAnzeige): ?>
    <div class="link-feld">
      <input value="<?= h($linkAnzeige['link']) ?>" readonly aria-label="<?= h(t('Neuer Link für {name}', ['name' => $linkAnzeige['name']])) ?>">
    </div>
  <?php endif; ?>
  <?php if (!$profile): ?>
    <p class="leer"><?= h(t('Noch keine Profile.')) ?></p>
  <?php else: ?>
  <div class="tabelle-wrap">
  <table class="liste admin-liste">
    <thead><tr><th></th><th><?= h(t('Name')) ?></th><th><?= h(t('E-Mail')) ?></th><th><?= h(t('Erstellt')) ?></th>
      <th><?= h(t('Letzte Anmeldung')) ?></th><th class="r"><?= h(t('Runden')) ?></th><th class="r"><?= h(t('Geräte')) ?></th><th><?= h(t('Aktionen')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($profile as $p): ?>
      <tr>
        <td><?= avatar_svg($p['avatar'], 32) ?></td>
        <td><strong><?= h($p['name']) ?></strong></td>
        <td><?= h($p['email']) ?></td>
        <td><?= h($datum($p['erstellt'])) ?></td>
        <td><?= h($datum($p['letzte_anmeldung'])) ?></td>
        <td class="r"><?= (int)$p['runden'] ?></td>
        <td class="r"><?= (int)$p['geraete'] ?></td>
        <td class="aktionen">
          <form method="post"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="aktion" value="link">
            <button type="submit" class="sekundaer klein-knopf" title="<?= h(t('Alter Link wird ungültig, alle Geräte werden abgemeldet.')) ?>"><?= h(t('Neuen Link senden')) ?></button></form>
          <details><summary class="sekundaer klein-knopf"><?= h(t('Umbenennen')) ?></summary>
            <form method="post" class="inline-form"><?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="aktion" value="umbenennen">
              <input name="name" maxlength="30" value="<?= h($p['name']) ?>" aria-label="<?= h(t('Neuer Name')) ?>">
              <button type="submit" class="klein-knopf"><?= h(t('Speichern')) ?></button></form>
          </details>
          <form method="post" onsubmit="return confirm(<?= h(json_encode(t('Profil «{name}» mit allen Runden löschen?', ['name' => $p['name']]), JSON_UNESCAPED_UNICODE)) ?>)">
            <?= csrf_feld() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="aktion" value="loeschen">
            <button type="submit" class="gefahr klein-knopf"><?= h(t('Löschen')) ?></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<section class="karte" id="mail">
  <h2><?= h(t('Mail-Einstellungen')) ?></h2>
  <?php if ($testErgebnis): ?><p class="<?= $testErgebnis[0] ? 'hinweis-ok' : 'meldung' ?>"><?= h($testErgebnis[1]) ?></p><?php endif; ?>
  <p class="leise"><?= h(t('Gespeichert in data/einstellungen.php (nicht im Programmcode). Die Angaben bekommst du von deinem Webhoster.')) ?></p>
  <form method="post" class="einstellungen-form">
    <?= csrf_feld() ?>
    <fieldset>
      <legend><?= h(t('Versand')) ?></legend>
      <label class="wahl-zeile"><input type="radio" name="mail_art" value="smtp" <?= $e['mail_art'] !== 'php' ? 'checked' : '' ?>> <?= h(t('SMTP-Server (empfohlen)')) ?></label>
      <label class="wahl-zeile"><input type="radio" name="mail_art" value="php" <?= $e['mail_art'] === 'php' ? 'checked' : '' ?>> <?= h(t('PHP mail() des Webhosters')) ?></label>
    </fieldset>
    <div class="feld-raster">
      <label><?= h(t('SMTP-Server')) ?><input name="smtp_host" value="<?= h((string)$e['smtp_host']) ?>" placeholder="mail.example.ch"></label>
      <label><?= h(t('Port')) ?><input name="smtp_port" type="number" min="1" max="65535" value="<?= (int)$e['smtp_port'] ?>"></label>
      <label><?= h(t('Verschlüsselung')) ?>
        <select name="smtp_sicherheit">
          <?php foreach (['starttls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'keine' => t('keine')] as $k => $titel): ?>
            <option value="<?= $k ?>" <?= $e['smtp_sicherheit'] === $k ? 'selected' : '' ?>><?= h($titel) ?></option>
          <?php endforeach; ?>
        </select></label>
      <label><?= h(t('Benutzername')) ?><input name="smtp_benutzer" value="<?= h((string)$e['smtp_benutzer']) ?>" autocomplete="off"></label>
      <label><?= h(t('Passwort')) ?><input name="smtp_passwort" type="password" autocomplete="new-password"
        placeholder="<?= h($e['smtp_passwort'] !== '' ? t('gesetzt – leer lassen, um es zu behalten') : '') ?>"></label>
      <?php if ($e['smtp_passwort'] !== ''): ?>
        <label class="wahl-zeile"><input type="checkbox" name="passwort_loeschen" value="1"> <?= h(t('Passwort entfernen')) ?></label>
      <?php endif; ?>
      <label><?= h(t('Absender-Adresse')) ?><input name="absender_mail" type="email" value="<?= h((string)$e['absender_mail']) ?>" placeholder="noreply@example.ch"></label>
      <label><?= h(t('Absender-Name')) ?><input name="absender_name" value="<?= h((string)$e['absender_name']) ?>"></label>
      <label class="breit"><?= h(t('Adresse der Seite (für die Links in den Mails)')) ?>
        <input name="basis_url" value="<?= h((string)$e['basis_url']) ?>" placeholder="<?= h(basis_url()) ?>">
        <small class="leise"><?= h(t('Leer = automatisch: {url}', ['url' => basis_url()])) ?></small></label>
    </div>
    <div class="abschluss links">
      <button type="submit" name="aktion" value="mail_speichern"><?= h(t('Speichern')) ?></button>
      <input name="test_an" type="email" value="<?= h((string)($_GET['test'] ?? $e['absender_mail'])) ?>" aria-label="<?= h(t('Testmail an')) ?>" placeholder="<?= h(t('Testmail an')) ?>">
      <button type="submit" name="aktion" value="mail_test" class="sekundaer"><?= h(t('Speichern und Testmail senden')) ?></button>
    </div>
  </form>
</section>
<?php fuss();
