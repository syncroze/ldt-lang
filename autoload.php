<?php

declare(strict_types=1);

/**
 * Minimal PSR-4 autoloader for the Ldtlang\ namespace, so the engine runs with
 * plain PHP (no Composer required). composer.json is also provided for projects
 * that prefer Composer's autoloader.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Ldtlang\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
