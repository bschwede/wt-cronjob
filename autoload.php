<?php
declare(strict_types=1);

/**
 * PSR-4-require_once-Loader für das Modul.
 *
 * Zwei Präfixe:
 *   Schwendinger\Webtrees\Module\Cronjob\  →  src/
 *   Cron\                                  →  vendor/dragonmantank/cron-expression/src/
 *
 * Die Cron-Lib wird bewusst NICHT über einen Composer-Autoloader geladen:
 * die Zielmaschine hat keinen Composer/keine Internetverbindung, die Lib ist
 * statisch gebündelt (Versions-Pin in composer.lock, Doku in README.md).
 */

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Schwendinger\\Webtrees\\Module\\Cronjob\\' => __DIR__ . '/src/',
        'Cron\\' => __DIR__ . '/vendor/dragonmantank/cron-expression/src/',
    ];

    foreach ($prefixes as $prefix => $base_dir) {
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, $len));
        $file = $base_dir . $relative . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }
});
