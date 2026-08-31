<?php
/*
 * webtrees - cronjob (custom module)
 *
 * Copyright (C) 2026 Bernd Schwendinger
 *
 * webtrees: online genealogy application
 * Copyright (C) 2026 webtrees development team.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

/*
 * Prepare translations + release metadata for the cronjob module (standalone).
 *
 * 1. Compile resources/lang/*.po into *.php - the same output as the core
 *    command app/Cli/Commands/CompilePoFiles (which only covers the core's own
 *    lang directory), but without the webtrees bootstrap / database. The
 *    compiled files are the runtime fast path: CronjobModule::customTranslations()
 *    prefers <lang>.php over <lang>.po.
 *    Like customTranslations(), the script follows the webtrees version
 *    branch: the 2.3 core stream loader (app/I18N/Translation.php) is
 *    preferred, with a fallback to the 2.2 fisharebest/localization package.
 * 2. Keep <module root>/latest-version.txt in sync with
 *    CronjobModule::CUSTOM_VERSION - but only when it changed ("update
 *    needed"): the file is the source of webtrees' module update check
 *    (CronjobModule::CUSTOM_LAST).
 *
 * Usage: php util/compile-po.php [module-root]
 * No database, no network, no webtrees bootstrap.
 */

$root = rtrim(rtrim((string) ($argv[1] ?? dirname(__DIR__)), '/\\'), '/') . '/';

// ---------------------------------------------------------------------------
// 1. PO -> PHP (byte-identical output to the core CompilePoFiles command)
// ---------------------------------------------------------------------------
$po_files = glob($root . 'resources/lang/*.po') ?: [];
if ($po_files === []) {
    echo "no PO files in resources/lang/ - nothing to compile" . PHP_EOL;
} else {
    // Same version branch as CronjobModule::customTranslations(): webtrees 2.3
    // replaced fisharebest/localization with its own stream-based loader.
    // This script has no composer autoloader, so the branch is decided by
    // file_exists() on the class files instead of class_exists().
    $class_2_3 = $root . '../../app/I18N/Translation.php';
    $class_2_2 = $root . '../../vendor/fisharebest/localization/src/Translation.php';

    if (file_exists($class_2_3)) {
        require $class_2_3;
        $to_array = static function (string $po_file): array|false {
            $stream = fopen($po_file, 'rb');
            if ($stream === false) {
                return false;
            }
            try {
                return \Fisharebest\Webtrees\I18N\Translation::fromPoStream($stream)->toArray();
            } finally {
                fclose($stream);
            }
        };
    } elseif (file_exists($class_2_2)) {
        require $class_2_2;
        $to_array = static function (string $po_file): array|false {
            $translation = new \Fisharebest\Localization\Translation($po_file);
            return $translation->asArray();
        };
    } else {
        fwrite(STDERR, "ERROR: no translation class found (looked for $class_2_3 [webtrees 2.3] and $class_2_2 [webtrees 2.2])" . PHP_EOL);
        exit(1);
    }

    $error = false;
    foreach ($po_files as $po_file) {
        $translations = $to_array($po_file);
        if ($translations === false) {
            fwrite(STDERR, "ERROR: failed to read $po_file" . PHP_EOL);
            $error = true;
            continue;
        }
        $php_file = substr($po_file, 0, -3) . '.php';
        $code     = "<?php\n\nreturn " . var_export($translations, true) . ";\n";
        if (file_put_contents($php_file, $code) === false) {
            fwrite(STDERR, "ERROR: failed to write $php_file" . PHP_EOL);
            $error = true;
        } else {
            echo 'Created ' . basename($php_file) . ' with ' . count($translations) . " translations" . PHP_EOL;
        }
    }
    if ($error) {
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 2. latest-version.txt (only when CUSTOM_VERSION changed)
// ---------------------------------------------------------------------------
$module_src = $root . 'src/CronjobModule.php';
if (!is_file($module_src)
    || preg_match("/public const CUSTOM_VERSION\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+[A-Za-z0-9\-]*)'/", (string) file_get_contents($module_src), $m) !== 1
) {
    fwrite(STDERR, "ERROR: CUSTOM_VERSION not found in $module_src" . PHP_EOL);
    exit(1);
}
$version = $m[1];

$version_file = $root . 'latest-version.txt';
$current     = is_file($version_file) ? trim((string) file_get_contents($version_file)) : '';
if ($current === $version) {
    echo "latest-version.txt already current ($version)" . PHP_EOL;
} else {
    if (file_put_contents($version_file, $version . "\n") === false) {
        fwrite(STDERR, "ERROR: failed to write $version_file" . PHP_EOL);
        exit(1);
    }
    echo 'latest-version.txt ' . ($current === '' ? 'created' : "updated: $current ->") . " $version" . PHP_EOL;
}

exit(0);
