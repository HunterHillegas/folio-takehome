<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (preg_match('#^/d/([^/]+)/([^/]+)$#', $path, $matches) === 1) {
    $_GET['slug'] = rawurldecode($matches[1]);
    $_GET['token'] = rawurldecode($matches[2]);
    require __DIR__ . '/view.php';
    return true;
}

if (preg_match('#^/s/([^/]+)$#', $path, $matches) === 1) {
    $_GET['token'] = rawurldecode($matches[1]);
    require __DIR__ . '/view.php';
    return true;
}

return false;
