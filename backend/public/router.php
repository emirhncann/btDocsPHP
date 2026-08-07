<?php

declare(strict_types=1);

// PHP built-in server router
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode($path);

// /admin → /admin/ (relative asset yolları için şart)
if ($path === '/admin') {
    $query = $_SERVER['QUERY_STRING'] ?? '';
    $target = '/admin/' . ($query !== '' ? ('?' . $query) : '');
    header('Location: ' . $target, true, 301);
    exit;
}

$file = __DIR__ . $path;

// Statik dosya
if ($path !== '/' && is_file($file)) {
    return false;
}

// Klasör → index.html varsa onu sun
if (is_dir($file)) {
    $indexHtml = rtrim($file, '\\/') . DIRECTORY_SEPARATOR . 'index.html';
    if (is_file($indexHtml)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($indexHtml);
        return true;
    }
}

require __DIR__ . '/index.php';
