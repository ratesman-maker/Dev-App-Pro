<?php
/**
 * Autoloader pro DupArchive knihovnu (Duplicator Pro).
 */

// Konstanty, které Duplicator Pro očekává
if (!defined('MB_IN_BYTES')) {
    define('MB_IN_BYTES', 1024 * 1024);
}
if (!defined('KB_IN_BYTES')) {
    define('KB_IN_BYTES', 1024);
}
if (!defined('DUPLICATOR_PLUGIN_VERSION')) {
    define('DUPLICATOR_PLUGIN_VERSION', '5.0.1');
}

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Duplicator\\Libs\\DupArchive\\')) {
        return;
    }
    $relative = substr($class, strlen('Duplicator\\Libs\\DupArchive\\'));
    $file = __DIR__ . '/DupArchive/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});
