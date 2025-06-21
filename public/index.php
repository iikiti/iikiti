<?php

use iikiti\CMS\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\ErrorHandler\Debug;
use Symfony\Component\HttpFoundation\Request;

define('PSR4LOADER', require dirname(__DIR__) . '/vendor/autoload.php');

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if (((bool) ($_SERVER['APP_DEBUG'] ?? '0')) == false) {
    umask(0000);

    Debug::enable();
}

if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? '') {
    Request::setTrustedProxies(
        explode(',', $trustedProxies),
        Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_PROTO
);
}

if ($trustedHosts = ((bool) ($_SERVER['TRUSTED_HOSTS'] ?? false))) {
    Request::setTrustedHosts([$trustedHosts]);
}

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'DEV', (bool) ($_SERVER['APP_DEBUG'] ?? false));
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
