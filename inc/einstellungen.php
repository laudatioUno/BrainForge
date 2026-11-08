<?php
/*
 * Einstellungen, die im Admin-Bereich geändert werden (Mailversand, Adresse der Seite).
 * Sie liegen in data/einstellungen.php – im geschützten Datenordner, damit ein Update
 * der Programmdateien sie nicht überschreibt.
 */
declare(strict_types=1);

const EINSTELLUNGEN_STANDARD = [
    'mail_art'        => 'smtp',      // 'smtp' oder 'php' (PHP-Funktion mail())
    'smtp_host'       => '',
    'smtp_port'       => 587,
    'smtp_sicherheit' => 'starttls',  // 'starttls', 'ssl' oder 'keine'
    'smtp_benutzer'   => '',
    'smtp_passwort'   => '',
    'absender_mail'   => '',
    'absender_name'   => 'BrainForge',
    'basis_url'       => '',          // leer = automatisch aus der Adresse im Browser
];

function einstellungen_datei(): string
{
    return dirname(DB_DATEI) . '/einstellungen.php';
}

function einstellungen(): array
{
    static $cache = null;
    if ($cache === null) {
        $gelesen = [];
        if (is_file(einstellungen_datei())) {
            $gelesen = include einstellungen_datei();
        }
        $cache = array_merge(EINSTELLUNGEN_STANDARD, is_array($gelesen) ? $gelesen : []);
    }
    return $cache;
}

function einstellung(string $name): mixed
{
    return einstellungen()[$name] ?? null;
}

/** Speichert die Einstellungen (nur bekannte Schlüssel). Gibt false zurück, wenn die Datei nicht geschrieben werden kann. */
function einstellungen_speichern(array $neu): bool
{
    $werte = array_intersect_key(array_merge(einstellungen(), $neu), EINSTELLUNGEN_STANDARD);
    $inhalt = "<?php\n// Von BrainForge im Admin-Bereich geschrieben. Nicht von Hand bearbeiten nötig.\nreturn "
        . var_export($werte, true) . ";\n";
    $datei = einstellungen_datei();
    $tmp = $datei . '.tmp';
    if (@file_put_contents($tmp, $inhalt, LOCK_EX) === false) {
        return false;
    }
    @chmod($tmp, 0640);
    if (!@rename($tmp, $datei)) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('opcache_invalidate')) {
        @opcache_invalidate($datei, true);
    }
    return true;
}

function mail_eingerichtet(): bool
{
    $e = einstellungen();
    return $e['absender_mail'] !== '' && ($e['mail_art'] === 'php' || $e['smtp_host'] !== '');
}

/** Adresse der Seite (für Links in Mails), immer mit / am Ende. */
function basis_url(): string
{
    $url = trim((string)einstellung('basis_url'));
    if ($url === '') {
        $https = ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off'
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $pfad = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $url = ($https ? 'https' : 'http') . '://' . $host . ($pfad === '/' ? '' : $pfad);
    }
    return rtrim($url, '/') . '/';
}
