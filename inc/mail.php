<?php
/*
 * Mailversand über SMTP (ohne Zusatzbibliotheken) oder über die PHP-Funktion mail().
 * Einstellungen: Admin-Bereich › Mail-Einstellungen.
 */
declare(strict_types=1);

/**
 * Schickt eine Text-Mail. Gibt null zurück, wenn es geklappt hat, sonst eine Fehlermeldung.
 */
function mail_senden(string $an, string $betreff, string $text): ?string
{
    $e = einstellungen();
    if (!mail_eingerichtet()) {
        return t('Der Mailversand ist noch nicht eingerichtet (Admin › Mail-Einstellungen).');
    }
    if (!filter_var($an, FILTER_VALIDATE_EMAIL)) {
        return t('Ungültige E-Mail-Adresse.');
    }
    $absender = (string)$e['absender_mail'];
    $name = (string)$e['absender_name'];
    $kopf = [
        'Date' => date(DATE_RFC2822),
        'From' => mail_adresse($absender, $name),
        'To' => $an,
        'Subject' => mail_kodieren($betreff),
        'Message-ID' => '<' . bin2hex(random_bytes(12)) . '@' . (substr(strrchr($absender, '@') ?: '@brainforge', 1)) . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        // Quoted-Printable: der Text bleibt lesbar. Base64 für reinen Text werten Spamfilter negativ.
        'Content-Transfer-Encoding' => 'quoted-printable',
        'Auto-Submitted' => 'auto-generated',
    ];
    $koerper = quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $text));

    if ($e['mail_art'] === 'php') {
        $zeilen = [];
        foreach ($kopf as $k => $v) {
            if ($k !== 'To' && $k !== 'Subject') {
                $zeilen[] = $k . ': ' . $v;
            }
        }
        $ok = @mail($an, $kopf['Subject'], $koerper, implode("\r\n", $zeilen), '-f' . $absender);
        return $ok ? null : t('PHP mail() hat die Mail nicht angenommen.');
    }

    $daten = '';
    foreach ($kopf as $k => $v) {
        $daten .= $k . ': ' . $v . "\r\n";
    }
    $daten .= "\r\n" . $koerper;
    try {
        smtp_senden($e, $absender, $an, $daten);
        return null;
    } catch (RuntimeException $ex) {
        return $ex->getMessage();
    }
}

function mail_kodieren(string $text): string
{
    return preg_match('/[^\x20-\x7e]/', $text) ? '=?UTF-8?B?' . base64_encode($text) . '?=' : $text;
}

function mail_adresse(string $adresse, string $name): string
{
    $name = trim(str_replace(['"', "\r", "\n"], '', $name));
    return $name === '' ? $adresse : mail_kodieren($name) . ' <' . $adresse . '>';
}

/** Minimaler SMTP-Client: EHLO, STARTTLS/SSL, AUTH, MAIL FROM, RCPT TO, DATA. */
function smtp_senden(array $e, string $von, string $an, string $daten): void
{
    $host = trim((string)$e['smtp_host']);
    $port = (int)$e['smtp_port'] ?: 587;
    $sicherheit = (string)$e['smtp_sicherheit'];
    $ziel = ($sicherheit === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $kontext = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
    $verbindung = @stream_socket_client($ziel, $nr, $fehler, 15, STREAM_CLIENT_CONNECT, $kontext);
    if (!$verbindung) {
        throw new RuntimeException(t('Keine Verbindung zum Mailserver {server}:', ['server' => $host . ':' . $port]) . ' ' . $fehler);
    }
    stream_set_timeout($verbindung, 20);

    $lesen = function (array $erwartet) use ($verbindung): string {
        $antwort = '';
        while (($zeile = fgets($verbindung, 1024)) !== false) {
            $antwort .= $zeile;
            if (strlen($zeile) < 4 || $zeile[3] !== '-') {
                break;
            }
        }
        $code = (int)substr($antwort, 0, 3);
        if (!in_array($code, $erwartet, true)) {
            throw new RuntimeException(t('Der Mailserver meldet:') . ' ' . trim($antwort ?: 'keine Antwort'));
        }
        return $antwort;
    };
    $senden = function (string $befehl, array $erwartet) use ($verbindung, $lesen): string {
        fwrite($verbindung, $befehl . "\r\n");
        return $lesen($erwartet);
    };

    try {
        $lesen([220]);
        $name = preg_replace('/[^A-Za-z0-9.-]/', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'localhost';
        $ehlo = $senden('EHLO ' . $name, [250]);
        if ($sicherheit === 'starttls') {
            $senden('STARTTLS', [220]);
            $methode = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $methode |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (!@stream_socket_enable_crypto($verbindung, true, $methode)) {
                throw new RuntimeException(t('Verschlüsselung (STARTTLS) mit dem Mailserver fehlgeschlagen.'));
            }
            $ehlo = $senden('EHLO ' . $name, [250]);
        }
        $benutzer = (string)$e['smtp_benutzer'];
        if ($benutzer !== '') {
            if (stripos($ehlo, 'PLAIN') !== false) {
                $senden('AUTH PLAIN ' . base64_encode("\0" . $benutzer . "\0" . $e['smtp_passwort']), [235]);
            } else {
                $senden('AUTH LOGIN', [334]);
                $senden(base64_encode($benutzer), [334]);
                $senden(base64_encode((string)$e['smtp_passwort']), [235]);
            }
        }
        $senden('MAIL FROM:<' . $von . '>', [250]);
        $senden('RCPT TO:<' . $an . '>', [250, 251]);
        $senden('DATA', [354]);
        // Zeilen, die mit einem Punkt beginnen, verdoppeln (SMTP-Regel)
        $daten = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], rtrim($daten, "\r\n")));
        $senden($daten . "\r\n.", [250]);
        $senden('QUIT', [221]);
    } finally {
        fclose($verbindung);
    }
}
