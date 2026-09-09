<?php

declare(strict_types=1);

// Router for the local Docker development server.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(__DIR__.rawurldecode($path ?: '/'));

if ($file !== false && str_starts_with($file, __DIR__.DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require __DIR__.'/index.php';
