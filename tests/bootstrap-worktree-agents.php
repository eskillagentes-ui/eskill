<?php

declare(strict_types=1);

/**
 * Bootstrap temporário para worktree sem vendor próprio:
 * 1) autoload do vendor compartilhado (main tree)
 * 2) prepend PSR-4 App\ → app/ desta worktree
 * 3) autoload de Tests\ desta worktree
 */
$sharedVendor = '/home/eskill/htdocs/eskill.com.br/vendor/autoload.php';
if (!is_file($sharedVendor)) {
    fwrite(STDERR, "Shared vendor autoload not found: {$sharedVendor}\n");
    exit(1);
}
require $sharedVendor;

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = dirname(__DIR__) . '/tests/' . $relative . '.php';
    if (is_file($file)) {
        require_once $file;
    }
}, true, true);

// Helpers do projeto (files autoload) — preferir worktree
foreach ([
    'app/Helpers/LogHelper.php',
    'app/Helpers/CacheHelper.php',
    'app/Helpers/Functions.php',
    'app/Helpers/PregaoHelper.php',
] as $helper) {
    $path = dirname(__DIR__) . '/' . $helper;
    if (is_file($path)) {
        require_once $path;
    }
}
