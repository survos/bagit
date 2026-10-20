<?php

declare(strict_types=1);

foreach ([dirname(__DIR__).'/vendor/autoload.php', dirname(__DIR__, 3).'/vendor/autoload.php'] as $autoload) {
    if (!defined('BAGIT_CORE_ONLY') && is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'Survos\\BagIt\\';
    if (str_starts_with($class, $prefix)) {
        $file = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
