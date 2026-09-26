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
];
