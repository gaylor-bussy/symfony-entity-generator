<?php

declare(strict_types=1);

if (is_file(__DIR__.'/vendor/autoload.php')) {
    require_once __DIR__.'/vendor/autoload.php';
}
spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'EntityGenerator\\')) {
        $file = __DIR__.'/src/'.substr($class, strlen('EntityGenerator\\')).'.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
