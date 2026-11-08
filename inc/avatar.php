<?php
/*
 * Zufällige, lustige Profil-Icons: kleine Monster, aus einem kurzen Zufallswert (Seed)
 * immer gleich erzeugt. Gespeichert wird nur der Seed.
 */
declare(strict_types=1);

function avatar_neu(): string
{
    return bin2hex(random_bytes(4));
}

function avatar_gueltig(string $seed): bool
{
    return (bool)preg_match('/^[0-9a-f]{8}$/', $seed);
}

/** Hauptfarbe des Monsters (z. B. für kleine Punkte). */
function avatar_farbe(string $seed): string
{
    return avatar_teile($seed)['koerper'];
}

function avatar_teile(string $seed): array
{
    $b = array_values(unpack('C*', hash('sha256', 'bf-avatar-' . $seed, true)));
    $koerper = ['#7c5cd6', '#e8590c', '#2b8a3e', '#0b7285', '#c2255c', '#1971c2', '#d9480f', '#5c940d', '#9c36b5', '#e67700'];
    $grund = ['#ffe3c2', '#d3f0e0', '#dbe7ff', '#fde2ef', '#fff3bf', '#e3fafc', '#f3f0ff', '#ffe8cc'];
    $muender = [
        'M25 50q7 6 14 0',        // Lächeln
        'M24 49q8 9 16 0z',       // offener Mund
        'M26 51h12',              // gerade
        'M25 52q7-5 14 0',        // erstaunt-schief
        'M26 49q3 4 6 0q3 4 6 0', // Wellenmund
    ];
    return [
        'grund' => $grund[$b[0] % count($grund)],
        'koerper' => $koerper[$b[1] % count($koerper)],
        'extra' => ['hoerner', 'ohren', 'antenne', 'keins'][$b[2] % 4],
        'augen' => $b[3] % 5 === 0 ? 1 : 2,
        'blick' => [-1.5, 0, 1.5][$b[4] % 3],
        'mund' => $muender[$b[5] % count($muender)],
        'flecken' => $b[6] % 3 === 0,
        'zaehne' => $b[7] % 4 === 0,
    ];
}

/** SVG des Icons. $titel leer = rein dekorativ (aria-hidden). */
function avatar_svg(string $seed, int $groesse = 40, string $titel = ''): string
{
    $t = avatar_teile(avatar_gueltig($seed) ? $seed : '00000000');
    $k = $t['koerper'];
    $s = '<svg class="avatar" width="' . $groesse . '" height="' . $groesse . '" viewBox="0 0 64 64"'
        . ($titel !== '' ? ' role="img" aria-label="' . h($titel) . '"' : ' aria-hidden="true"') . '>';
    $s .= '<circle cx="32" cy="32" r="32" fill="' . $t['grund'] . '"/>';
    if ($t['extra'] === 'hoerner') {
        $s .= '<path d="M22 27l-5-11 10 6zM42 27l5-11-10 6z" fill="' . $k . '"/>';
    } elseif ($t['extra'] === 'ohren') {
        $s .= '<circle cx="17" cy="29" r="7" fill="' . $k . '"/><circle cx="47" cy="29" r="7" fill="' . $k . '"/>';
    } elseif ($t['extra'] === 'antenne') {
        $s .= '<path d="M32 25V11" stroke="' . $k . '" stroke-width="3" stroke-linecap="round"/><circle cx="32" cy="10" r="4" fill="#f2a33a"/>';
    }
    $s .= '<path d="M12 64c0-20 8-40 20-40s20 20 20 40z" fill="' . $k . '"/>';
    if ($t['flecken']) {
        $s .= '<circle cx="18" cy="52" r="3" fill="#fff" opacity=".3"/><circle cx="46" cy="56" r="2.5" fill="#fff" opacity=".3"/><circle cx="42" cy="46" r="1.8" fill="#fff" opacity=".3"/>';
    }
    $b = $t['blick'];
    if ($t['augen'] === 1) {
        $s .= '<circle cx="32" cy="37" r="8.5" fill="#fff"/><circle cx="' . (33 + $b) . '" cy="38" r="3.8" fill="#1f2430"/>';
    } else {
        $s .= '<circle cx="25" cy="38" r="6" fill="#fff"/><circle cx="39" cy="38" r="6" fill="#fff"/>'
            . '<circle cx="' . (25.5 + $b) . '" cy="39" r="2.8" fill="#1f2430"/><circle cx="' . (39.5 + $b) . '" cy="39" r="2.8" fill="#1f2430"/>';
    }
    $s .= '<path d="' . $t['mund'] . '" stroke="#1f2430" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/>';
    if ($t['zaehne']) {
        $s .= '<path d="M29 50.5l1.5 3 1.5-3zM33 50.5l1.5 3 1.5-3z" fill="#fff"/>';
    }
    return $s . '</svg>';
}
