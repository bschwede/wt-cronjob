<?php
declare(strict_types=1);

/**
 * PSR-4-require_once-Loader für das Modul.
 *
 * Option Y (bschwede/wt-shared-libs): der Shared-Skew braucht Composer
 * (ClassLoader + InstalledVersions) aus dem webtrees-Core-Vendor. Die CLI-
 * Skripte laden diese Datei VOR CliBootstrap::autoload(), daher hier den Core-
 * Autoloader sicherstellen (no-op, wenn webtrees ihn bereits geladen hat, wie
 * im UI-Kontext). Danach der Skew, dann die modul-eigenen Präfixe:
 *   Schwendinger\Webtrees\Module\Cronjob\  →  src/
 *   Cron\                                  →  vendor/dragonmantank/cron-expression/src/Cron/
 *
 * Die Cron-Lib wird bewusst NICHT über einen Composer-Autoloader geladen:
 * die Zielmaschine hat keinen Composer/keine Internetverbindung, die Lib ist
 * statisch gebündelt (Versions-Pin in composer.lock, Doku in README.md).
 */

// webtrees-Root (dynamisch, index.php) finden, damit der Core-Autoloader auch
// ohne bereits laufenden webtrees-Bootstrap gefunden werden kann.
$root = __DIR__;
for ($i = 0; $i < 14; $i++) {
    if (is_file($root . '/index.php')) {
        break;
    }
    $parent = dirname($root);
    if ($parent === $root) {
        break;
    }
    $root = $parent;
}
require_once $root . '/vendor/autoload.php';

require __DIR__ . '/vendor/bschwede/wt-shared-libs/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'Schwendinger\\Webtrees\\Module\\Cronjob\\' => __DIR__ . '/src/',
        'Cron\\' => __DIR__ . '/vendor/dragonmantank/cron-expression/src/Cron/',
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
