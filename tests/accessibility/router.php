<?php

declare(strict_types=1);

/*
 * Router for `php -S` used by the accessibility scan (see
 * docs/accessibility.md and tests/accessibility/scan.mjs).
 *
 * PHP's built-in web server (cli-server SAPI) does not import the process
 * environment into $_SERVER the way php-cli does: getenv() sees APP_ENV etc.
 * but $_SERVER does not. Symfony Runtime builds the kernel context from
 * $_SERVER, so without this bridge the server silently boots the dev kernel
 * with .env defaults no matter what the caller exported. Bridging the
 * allow-listed variables here makes `APP_ENV=test php -S ... router.php`
 * behave the way every CI reader expects.
 */
foreach ([
    'APP_ENV',
    'APP_DEBUG',
    'APP_SECRET',
    'DATABASE_URL',
    'MESSENGER_TRANSPORT_DSN',
    'MAILER_DSN',
    'GOOGLE_RECAPTCHA_SITE_KEY',
    'GOOGLE_RECAPTCHA_SECRET',
    'JSON_HUB_PROJECT',
    'MAILING_PROVIDER_ROUTING_KEY',
] as $name) {
    $value = getenv($name);
    if (false !== $value) {
        $_SERVER[$name] = $_ENV[$name] = $value;
    }
}

$publicDir = dirname(__DIR__, 2).'/public';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ('/' !== $path && is_file($publicDir.$path)) {
    return false;
}

$_SERVER['SCRIPT_FILENAME'] = $publicDir.'/index.php';

require $publicDir.'/index.php';
