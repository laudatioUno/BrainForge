<?php
/*
 * Profile mit persönlichem Link (Magic Link) und angemeldeten Geräten.
 *
 * - Der persönliche Link enthält einen langen Zufallsschlüssel; gespeichert wird nur sein Hash.
 * - Wer den Link öffnet, bekommt auf diesem Gerät ein eigenes Geräte-Cookie (ebenfalls nur als
 *   Hash gespeichert) und bleibt angemeldet, bis er sich abmeldet.
 * - Ein neuer Link macht den alten ungültig und meldet Geräte ab (Schutz, falls er weitergegeben wurde).
 */
declare(strict_types=1);

const GERAET_COOKIE = 'bf_geraet';
const GERAET_DAUER = 400 * 86400; // so lange bleibt ein Gerät angemeldet (Maximum der Browser)

function zufallsschluessel(): string
{
    return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
}

function schluessel_hash(string $schluessel): string
{
    return hash('sha256', $schluessel);
}

function ist_https(): bool
{
    return (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function jetzt(): string
{
    return date('Y-m-d H:i:s');
}

/** Persönlicher Link zu einem Schlüssel. */
function profil_link(string $schluessel): string
{
    return basis_url() . 'anmelden.php?k=' . rawurlencode($schluessel);
}

/** Das auf diesem Gerät angemeldete Profil oder null. */
function profil(): ?array
{
    static $geladen = false, $profil = null;
    if ($geladen) {
        return $profil;
    }
    $geladen = true;
    $cookie = $_COOKIE[GERAET_COOKIE] ?? '';
    if (!is_string($cookie) || strlen($cookie) < 20 || update_noetig()) {
        return null;
    }
    $st = db()->prepare('SELECT p.*, g.id AS geraet_id, g.zuletzt AS geraet_zuletzt FROM geraete g
        JOIN profile p ON p.id = g.profil_id WHERE g.token_hash = ?');
    $st->execute([schluessel_hash($cookie)]);
    $profil = $st->fetch() ?: null;
    if ($profil === null) {
        geraet_cookie_loeschen();
        return null;
    }
    // Letzte Aktivität nachführen (höchstens alle 10 Minuten schreiben)
    if (strtotime((string)$profil['geraet_zuletzt']) < time() - 600) {
        db()->prepare('UPDATE geraete SET zuletzt = ? WHERE id = ?')->execute([jetzt(), $profil['geraet_id']]);
        db()->prepare('UPDATE profile SET letzte_anmeldung = ? WHERE id = ?')->execute([jetzt(), $profil['id']]);
    }
    return $profil;
}

function geraet_cookie_setzen(string $wert): void
{
    setcookie(GERAET_COOKIE, $wert, [
        'expires' => time() + GERAET_DAUER, 'path' => '/', 'secure' => ist_https(),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    $_COOKIE[GERAET_COOKIE] = $wert;
}

function geraet_cookie_loeschen(): void
{
    if (!headers_sent()) {
        setcookie(GERAET_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'secure' => ist_https(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    unset($_COOKIE[GERAET_COOKIE]);
}

/** Meldet dieses Gerät für ein Profil an. */
function geraet_anmelden(int $profilId): void
{
    $token = zufallsschluessel();
    db()->prepare('INSERT INTO geraete (profil_id, token_hash, erstellt, zuletzt) VALUES (?,?,?,?)')
        ->execute([$profilId, schluessel_hash($token), jetzt(), jetzt()]);
    db()->prepare('UPDATE profile SET letzte_anmeldung = ? WHERE id = ?')->execute([jetzt(), $profilId]);
    geraet_cookie_setzen($token);
    session_regenerate_id(true);
}

/** Meldet dieses Gerät ab. */
function geraet_abmelden(): void
{
    $cookie = $_COOKIE[GERAET_COOKIE] ?? '';
    if (is_string($cookie) && $cookie !== '') {
        db()->prepare('DELETE FROM geraete WHERE token_hash = ?')->execute([schluessel_hash($cookie)]);
    }
    geraet_cookie_loeschen();
    session_regenerate_id(true);
}

/** Profil zu einem persönlichen Link oder null. */
function profil_zum_link(string $schluessel): ?array
{
    if (strlen($schluessel) < 20) {
        return null;
    }
    $st = db()->prepare('SELECT * FROM profile WHERE link_hash = ?');
    $st->execute([schluessel_hash($schluessel)]);
    return $st->fetch() ?: null;
}

/** Legt ein Profil an. Gibt [id, schluessel] zurück. */
function profil_erstellen(string $name, string $email, string $avatar): array
{
    $schluessel = zufallsschluessel();
    db()->prepare('INSERT INTO profile (name, email, avatar, sprache, link_hash, erstellt) VALUES (?,?,?,?,?,?)')
        ->execute([$name, $email, $avatar, sprache(), schluessel_hash($schluessel), jetzt()]);
    return [(int)db()->lastInsertId(), $schluessel];
}

/**
 * Erzeugt einen neuen persönlichen Link. Der alte wird ungültig, und alle Geräte werden
 * abgemeldet – ausser dem mit $behalteGeraet (das eigene, wenn man selbst einen neuen Link holt).
 */
function profil_neuer_link(int $profilId, ?int $behalteGeraet = null): string
{
    $schluessel = zufallsschluessel();
    db()->prepare('UPDATE profile SET link_hash = ? WHERE id = ?')->execute([schluessel_hash($schluessel), $profilId]);
    if ($behalteGeraet === null) {
        db()->prepare('DELETE FROM geraete WHERE profil_id = ?')->execute([$profilId]);
    } else {
        db()->prepare('DELETE FROM geraete WHERE profil_id = ? AND id <> ?')->execute([$profilId, $behalteGeraet]);
    }
    return $schluessel;
}

/**
 * Nach dem Erstellen eines Profils: die Gast-Runden, die auf diesem Gerät (in dieser Sitzung)
 * gespielt wurden, gehören jetzt zum neuen Profil.
 */
function profil_nach_anmeldung(int $profilId): void
{
    $meine = array_map('intval', $_SESSION['meine'] ?? []);
    if (!$meine) {
        return;
    }
    $p = db()->prepare('SELECT name FROM profile WHERE id = ?');
    $p->execute([$profilId]);
    $name = (string)$p->fetchColumn();
    $platz = implode(',', array_fill(0, count($meine), '?'));
    db()->prepare("UPDATE resultate SET profil_id = ?, name = ? WHERE profil_id IS NULL AND id IN ($platz)")
        ->execute(array_merge([$profilId, $name], $meine));
}

/** Bereinigt und prüft einen Spitznamen. Leer = ungültig. */
function spitzname(string $name): string
{
    $name = trim(preg_replace('/[\p{C}\s]+/u', ' ', $name) ?? '');
    return mb_substr($name, 0, 30);
}

function email_normal(string $email): string
{
    return mb_strtolower(trim($email));
}

/**
 * Schickt die persönlichen Links an eine Adresse. $links: [[name, link], …]
 * Gibt null bei Erfolg zurück, sonst die Fehlermeldung.
 */
function links_mailen(string $email, array $links, bool $neu): ?string
{
    $mehrere = count($links) > 1;
    $text = t('Hallo') . "\n\n";
    $text .= $neu
        ? t('Willkommen bei BrainForge! Mit diesem persönlichen Link kommst du direkt in dein Profil:')
        : ($mehrere ? t('Hier sind die neuen persönlichen Links für die Profile mit dieser E-Mail-Adresse:')
            : t('Hier ist dein neuer persönlicher Link:'));
    $text .= "\n\n";
    foreach ($links as [$name, $link]) {
        $text .= ($mehrere || !$neu ? $name . ":\n" : '') . $link . "\n\n";
    }
    if (!$neu) {
        $text .= t('Frühere Links funktionieren nicht mehr. Geräte, die mit einem alten Link angemeldet waren, wurden abgemeldet.') . "\n\n";
    }
    $text .= t('Tipp: Öffne den Link auf dem Handy und füge die Seite zum Home-Bildschirm hinzu. Gib den Link niemandem weiter – wer ihn hat, kann in deinem Profil üben.') . "\n\n";
    $text .= t('Falls du diese Mail nicht erwartet hast, kannst du sie ignorieren.') . "\n\n– BrainForge\n" . basis_url() . "\n";
    $betreff = $neu ? t('Dein BrainForge-Profil') : t('Dein neuer BrainForge-Link');
    return mail_senden($email, $betreff, $text);
}

/*
 * ---- Drossel: begrenzt Anfragen (Profile erstellen, Links anfordern, Admin-Login) ----
 */
function drossel(string $schluessel, int $maximum, int $sekunden): bool
{
    $grenze = time() - $sekunden;
    if (random_int(1, 50) === 1) {
        db()->prepare('DELETE FROM drossel WHERE zeit < ?')->execute([time() - 86400]);
    }
    $st = db()->prepare('SELECT COUNT(*) FROM drossel WHERE schluessel = ? AND zeit > ?');
    $st->execute([$schluessel, $grenze]);
    if ((int)$st->fetchColumn() >= $maximum) {
        return false;
    }
    db()->prepare('INSERT INTO drossel (schluessel, zeit) VALUES (?, ?)')->execute([$schluessel, time()]);
    return true;
}

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unbekannt');
}

/*
 * ---- Schutz für Formulare (CSRF) ----
 */
function csrf(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
}

function csrf_feld(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf()) . '">';
}

function csrf_ok(): bool
{
    return is_string($_POST['csrf'] ?? null) && hash_equals(csrf(), $_POST['csrf']);
}
