<?php
declare(strict_types=1);

$path = (string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . $path)) {
    return false;
}

$request = trim($path, '/');

if ($request === '') {
    require __DIR__ . '/index.php';
    exit;
}

if ($request === 'sitemap.xml') {
    require __DIR__ . '/sitemap.php';
    exit;
}

$_GET['code'] = $request;
require __DIR__ . '/view.php';
