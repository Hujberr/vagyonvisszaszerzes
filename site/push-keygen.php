<?php
/*
 * VAPID-kulcspár előállítása — csak parancssorból futtatható:  php push-keygen.php
 * A kiírt sorokat a counter-config.php-ba kell másolni. Webről nem érhető el.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
openssl_pkey_export($k, $pem);
$d = openssl_pkey_get_details($k)['ec'];
$pad = fn($x) => str_pad($x, 32, "\0", STR_PAD_LEFT);
$pub = rtrim(strtr(base64_encode("\x04" . $pad($d['x']) . $pad($d['y'])), '+/', '-_'), '=');
echo "    'vapid_public' => '" . $pub . "',\n";
echo "    'vapid_private_pem' => <<<PEM\n" . trim($pem) . "\nPEM,\n";
echo "    'push_token' => '" . bin2hex(random_bytes(32)) . "',\n";
