<?php
declare(strict_types=1);

/**
 * AISSeaStats bootstrap: autoloader, error handling, time zone.
 * Every entry point (web, API, CLI) requires this file first.
 */

namespace AISSeaStats;

const VERSION = '1.1.0';
const APP_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = __NAMESPACE__ . '\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

error_reporting(E_ALL);
ini_set('display_errors', PHP_SAPI === 'cli' ? '1' : '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');
