<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'EventGraph\\')) {
        $relative = substr($class, strlen('EventGraph\\'));
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $file = __DIR__ . DIRECTORY_SEPARATOR . $relativePath . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
