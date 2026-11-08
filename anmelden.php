<?php
declare(strict_types=1);
require __DIR__ . '/lib.php';

// Persönlicher Link: meldet dieses Gerät an und leitet weiter.
$schluessel = (string)($_GET['k'] ?? '');
$gedrosselt = !drossel('anmelden:' . client_ip(), 30, 600);
$p = $gedrosselt ? null : profil_zum_link($schluessel);

if ($p !== null) {
    $jetzt = profil();
    if ($jetzt === null || (int)$jetzt['id'] !== (int)$p['id']) {
        if ($jetzt !== null) {
            geraet_abmelden(); // anderes Profil auf diesem Gerät abmelden
        }
        geraet_anmelden((int)$p['id']);
    }
    if (isset(SPRACHEN[$p['sprache']]) && !isset($_COOKIE['sprache'])) {
        setcookie('sprache', $p['sprache'], ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
    }
    header('Location: index.php');
    exit;
}

http_response_code($gedrosselt ? 429 : 404);
kopf(t('Link ungültig'));
?>
<section class="karte start">
  <h1><?= h(t('Dieser Link funktioniert nicht mehr')) ?></h1>
  <p><?= h($gedrosselt
      ? t('Zu viele Versuche. Bitte in ein paar Minuten nochmals.')
      : t('Vielleicht wurde inzwischen ein neuer Link angefordert – dann ist der alte ungültig.')) ?></p>
  <div class="abschluss">
    <a class="knopf" href="link-vergessen.php"><?= h(t('Neuen Link anfordern')) ?></a>
    <a class="sekundaer" href="index.php"><?= h(t('Als Gast weiter')) ?></a>
  </div>
</section>
<?php fuss();
