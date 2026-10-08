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

// Architectural guard: the database boundary of the module.
//
// Every cj_* table has exactly ONE owner class. Any new DB::table('cj_…')
// or hasTable('cj_…') call outside the owner is a boundary violation and
// fails this suite (standalone, no webtrees bootstrap / DB needed).
//
// Current boundary:
//   cj_job / cj_run / cj_job_trigger    -> src/Services/JobRepository.php
//   cj_event / cj_event_run             -> src/Services/EventQueue.php
//   cj_event_catalog                    -> src/Services/EventCatalogService.php
//   cj_command_catalog                  -> src/Services/CommandCatalogService.php
//   (read-only hasTable('cj_job')       -> src/Services/CronjobService::isMigrated())
//
// Run: php modules_v4/cronjob/tests/test-db-boundary.php

namespace {

    $failures = 0;

    function check(string $name, bool $cond): void {
        global $failures;
        if ($cond) {
            echo "ok   - {$name}\n";
        } else {
            $failures++;
            echo "FAIL - {$name}\n";
        }
    }

    $module = dirname(__DIR__);

    $owners = [
        'cj_job'             => 'src/Services/JobRepository.php',
        'cj_run'             => 'src/Services/JobRepository.php',
        'cj_job_trigger'     => 'src/Services/JobRepository.php',
        'cj_event'           => 'src/Services/EventQueue.php',
        'cj_event_run'       => 'src/Services/EventQueue.php',
        'cj_event_catalog'   => 'src/Services/EventCatalogService.php',
        'cj_command_catalog' => 'src/Services/CommandCatalogService.php',
    ];

    // Documented exceptions: file => tables allowed for a read-only
    // hasTable() check (a DB query is still a violation there).
    $exceptions = [
        // isMigrated(): the public facade's read-only schema check.
        'src/Services/CronjobService.php' => ['cj_job'],
        // The migration itself: idempotent hasTable() checks before create.
        'src/Schema/Migration0.php' => ['cj_job', 'cj_run', 'cj_job_trigger', 'cj_event', 'cj_event_run', 'cj_event_catalog', 'cj_command_catalog'],
    ];

    $files = [];
    foreach (['src', 'cli'] as $dir) {
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($module . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($iter as $file) {
            if ($file->isFile() && substr($file->getFilename(), -4) === '.php') {
                $files[] = substr($file->getPathname(), strlen($module) + 1);
            }
        }
    }
    sort($files);

    check('scanned at least one PHP file', $files !== []);

    foreach ($owners as $table => $owner) {
        check("owner file for {$table} exists: {$owner}", is_file($module . '/' . $owner));
    }

    foreach ($files as $rel) {
        $src = (string) file_get_contents($module . '/' . $rel);
        preg_match_all("/DB::table\(\s*['\"](cj_[a-z_]+)['\"]/", $src, $m1);
        preg_match_all("/hasTable\(\s*['\"](cj_[a-z_]+)['\"]/", $src, $m2);

        foreach ($m1[1] as $table) {
            $owner = $owners[$table] ?? null;
            if ($owner === null) {
                check("{$rel}: cj table '{$table}' has no owner in the whitelist (add it)", false);
                continue;
            }
            check("{$rel}: DB::table('{$table}') is inside its owner ({$owner})", $rel === $owner);
        }
        foreach ($m2[1] as $table) {
            $owner = $owners[$table] ?? null;
            if ($owner === null) {
                check("{$rel}: cj table '{$table}' has no owner in the whitelist (add it)", false);
                continue;
            }
            $excepted = in_array($table, $exceptions[$rel] ?? [], true);
            check("{$rel}: hasTable('{$table}') is inside its owner ({$owner}) or a documented exception", $rel === $owner || $excepted);
        }
    }

    if ($failures === 0) {
        echo "All db-boundary tests passed.\n";
    } else {
        echo "{$failures} test(s) FAILED.\n";
    }
    exit($failures === 0 ? 0 : 1);
}
