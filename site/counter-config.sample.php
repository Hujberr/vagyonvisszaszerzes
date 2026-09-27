<?php
/*
 * MINTA — élesítéskor másold le counter-config.php néven, és töltsd ki.
 * A counter-config.php NEM kerülhet a GitHub-tárolóba (.gitignore kizárja).
 */
return [
    // Hosszú, véletlen titkos kulcs (pl. 64 hexa karakter).
    'secret' => 'IDE_EGY_HOSSZU_VELETLEN_KULCS',
    // Az adatbázis helye — lehetőleg a public_html-en KÍVÜL, pl. /home/FELHASZNALO/szamlalo/visits.sqlite
    'db_path' => __DIR__ . '/../szamlalo/visits.sqlite',
    // Az admin oldal jelszavának hash-e: php -r "echo password_hash('JELSZO', PASSWORD_DEFAULT);"
    'admin_password_hash' => '',
    // Induló érték a nyilvános számlálóhoz (alapból 0).
    'start_offset' => 0,

    // ---- Push-értesítések ----
    // A három alábbi sort a  php push-keygen.php  parancs írja ki (cPanel → Terminál).
    // A push_token értékét GitHub-titokként is fel kell venni (PUSH_TOKEN), mellé a PUSH_URL:
    // https://FODOMAIN.hu/push-send.php — majd az index.html-ben: SITE_CONFIG.pushEnabled = true.
    'vapid_public' => '',
    'vapid_private_pem' => '',
    'push_token' => '',
    'vapid_subject' => 'mailto:vagyonvisszaszerzes@gmail.com',
    // A feliratkozások adatbázisa — lehetőleg a public_html-en KÍVÜL.
    'push_db_path' => __DIR__ . '/../szamlalo/push.sqlite',
    'data_raw_base' => 'https://raw.githubusercontent.com/Hujberr/vagyonvisszaszerzes/',
];
