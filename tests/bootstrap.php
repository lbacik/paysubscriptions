<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// Git doesn't track file permissions beyond the executable bit, so a fresh
// checkout (e.g. CI) can leave this key world-readable; league/oauth2-server
// refuses to load a private key that isn't 600/660.
$oauthPrivateKey = dirname(__DIR__).'/tests/Fixtures/oauth/private.pem';
if (is_file($oauthPrivateKey)) {
    chmod($oauthPrivateKey, 0600);
}
