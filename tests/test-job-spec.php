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

// Standalone tests for the phase-2 self-registration building blocks that are
// free of webtrees/DB: ScheduleService::validateJobSpec(),
// CronjobUtils::loadManifestFile() and CliBootstrap::resolvePayloadPath().
// The webtrees root is a temp fixture (same convention as
// test-args-validator.php).
//
// Run: php modules_v4/cronjob/tests/test-job-spec.php

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\CronjobUtils;
use Schwendinger\Webtrees\Module\Cronjob\Services\CliBootstrap;
use Schwendinger\Webtrees\Module\Cronjob\Services\ScheduleService;

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

function rrm(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path)) {
            rrm($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$root = sys_get_temp_dir() . '/cronjob-jspec-' . uniqid();
@mkdir($root . '/modules_v4/fakemod/cli', 0777, true);
@mkdir($root . '/modules_v4/fakemod2/cli', 0777, true);
file_put_contents($root . '/modules_v4/fakemod/cli/real-job.php', '<?php // stub' . "\n");
file_put_contents($root . '/modules_v4/fakemod/cli/real-job.logic.php', '<?php // payload' . "\n");
file_put_contents($root . '/modules_v4/fakemod/cli/nologic.php', '<?php // stub without payload' . "\n");
file_put_contents($root . '/modules_v4/fakemod/offcli.php', '<?php // not in cli' . "\n");
file_put_contents($root . '/modules_v4/fakemod/offcli.logic.php', '<?php // payload not in cli' . "\n");
file_put_contents($root . '/index.logic.php', '<?php // payload outside modules_v4' . "\n");
file_put_contents($root . '/modules_v4/fakemod/cron-jobs.php',
    '<?php return [ [ "name" => "demo", "cron" => "*/30 * * * *",'
    . ' "command_type" => "module",'
    . ' "command" => "modules_v4/fakemod/cli/real-job.php",'
    . ' "args" => "--limit=10" ] ];' . "\n");
file_put_contents($root . '/modules_v4/fakemod2/cron-jobs.php',
    '<?php throw new RuntimeException("boom");' . "\n");
file_put_contents($root . '/modules_v4/fakemod2/no-array.php',
    '<?php return "not an array";' . "\n");

$command = 'modules_v4/fakemod/cli/real-job.php';

// --- validateJobSpec ---------------------------------------------------------
$r = ScheduleService::validateJobSpec([
    'name' => 'demo', 'cron' => '*/30 * * * *',
    'command_type' => 'module', 'command' => $command, 'args' => '--limit=10',
], $root);
check('valid spec: no errors', $r['errors'] === []);
check('valid spec: title defaults to name', $r['spec']['title'] === 'demo');
check('valid spec: enabled defaults to false', $r['spec']['enabled'] === false);
check('valid spec: timeout defaults to 300', $r['spec']['timeout_sec'] === 300);
check('valid spec: trigger_type normalized to time', $r['spec']['trigger_type'] === 'time');

$r = ScheduleService::validateJobSpec([
    'name' => 'demo', 'cron' => 'not a cron',
    'command_type' => 'module', 'command' => $command,
], $root);
check('invalid cron rejected', $r['errors'] !== []);

$r = ScheduleService::validateJobSpec([
    'name' => 'demo', 'cron' => '*/30 * * * *',
    'command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/../index.logic.php',
], $root);
check('path-escape command rejected', $r['errors'] !== []);

$r = ScheduleService::validateJobSpec([
    'name' => 'demo', 'cron' => '*/30 * * * *',
    'command_type' => 'module', 'command' => '',
], $root);
check('empty command rejected', $r['errors'] !== []);

$r = ScheduleService::validateJobSpec([
    'name' => 'demo', 'cron' => '*/30 * * * *',
    'command_type' => 'shell', 'command' => $command,
], $root);
check('unknown command_type rejected', $r['errors'] !== []);

$r = ScheduleService::validateJobSpec([
    'name' => 'demo', 'cron' => '*/30 * * * *',
    'command_type' => 'module', 'command' => $command, 'timeout_sec' => 99999,
], $root);
check('out-of-range timeout flagged', $r['errors'] !== []);
check('out-of-range timeout normalized to 300', $r['spec']['timeout_sec'] === 300);

$r = ScheduleService::validateJobSpec([
    'name' => 'Bad Name', 'cron' => '*/30 * * * *',
    'command_type' => 'module', 'command' => $command,
], $root);
check('non-slug name rejected', $r['errors'] !== []);

$r = ScheduleService::validateJobSpec([
    'name' => 'demo', 'title' => 'My Job', 'enabled' => true,
    'cron' => '*/30 * * * *',
    'command_type' => 'module', 'command' => $command,
], $root);
check('explicit title kept', $r['spec']['title'] === 'My Job');
check('explicit enabled=true kept', $r['spec']['enabled'] === true);

// event-triggered jobs (phase 2, §6.1)
$r = ScheduleService::validateJobSpec([
    'name' => 'on-dirty', 'trigger_type' => 'event', 'event_name' => 'index-dirty',
    'command_type' => 'module', 'command' => $command,
], $root);
check('event job: no errors', $r['errors'] === []);
check('event job: trigger_type normalized', $r['spec']['trigger_type'] === 'event');
check('event job: event_name kept', $r['spec']['event_name'] === 'index-dirty');
check('event job: cron cleared', $r['spec']['cron'] === '');

$r = ScheduleService::validateJobSpec([
    'name' => 'on-dirty', 'trigger_type' => 'event',
    'command_type' => 'module', 'command' => $command,
], $root);
check('event job without event_name rejected', $r['errors'] !== []);

$r = ScheduleService::validateJobSpec([
    'name' => 'on-dirty', 'trigger_type' => 'event', 'event_name' => 'Bad Name!',
    'command_type' => 'module', 'command' => $command,
], $root);
check('event job with bad event_name rejected', $r['errors'] !== []);

// --- loadManifestFile --------------------------------------------------------
$specs = CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod/cron-jobs.php');
check('manifest: valid returns list', is_array($specs) && count($specs) === 1 && ($specs[0]['name'] ?? '') === 'demo');
check('manifest: non-array returns []', CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod2/no-array.php') === []);
check('manifest: throwing returns []', CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod2/cron-jobs.php') === []);
check('manifest: missing file returns []', CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod2/nope.php') === []);

// --- findOfferedSpec -----------------------------------------------------------
$offered = [
    ['module' => '_fakemod_', 'spec' => ['name' => 'demo', 'title' => 'Demo', 'trigger_type' => 'time', 'cron' => '*/30 * * * *']],
    ['module' => 'fakemod2', 'spec' => ['name' => 'other', 'title' => 'Other', 'trigger_type' => 'time', 'cron' => '0 3 * * *']],
];
$spec = ScheduleService::findOfferedSpec('fakemod:demo', $offered);
check('findOfferedSpec: key match returns spec', $spec !== null && $spec['title'] === 'Demo');
check('findOfferedSpec: module part trimmed of underscores', $spec !== null && $spec['cron'] === '*/30 * * * *');
check('findOfferedSpec: unknown key -> null', ScheduleService::findOfferedSpec('fakemod:nope', $offered) === null);
check('findOfferedSpec: same name other module -> null', ScheduleService::findOfferedSpec('fakemod2:demo', $offered) === null);
check('findOfferedSpec: colon-less name -> null', ScheduleService::findOfferedSpec('demo', $offered) === null);

// --- uniqueCopySlug -------------------------------------------------------------
$slug = CronjobUtils::uniqueCopySlug('base', static fn (string $s): bool => $s === 'base-copy');
check('copySlug: -copy taken -> -copy2', $slug === 'base-copy2');
$slug = CronjobUtils::uniqueCopySlug('base', static fn (string $s): bool => false);
check('copySlug: -copy free -> -copy', $slug === 'base-copy');
$slug = CronjobUtils::uniqueCopySlug('base', static fn (string $s): bool => in_array($s, ['base-copy', 'base-copy2', 'base-copy3'], true));
check('copySlug: sequential until free', $slug === 'base-copy4');
$slug = CronjobUtils::uniqueCopySlug(str_repeat('a', 80), static fn (string $s): bool => false);
check('copySlug: long base truncated to fit 64 chars', strlen($slug) === 64 && str_ends_with($slug, '-copy'));
check('copySlugBase: module prefix stripped', CronjobUtils::copySlugBase('linkenhancer:link-index') === 'link-index');
check('copySlugBase: plain name unchanged', CronjobUtils::copySlugBase('link-index') === 'link-index');
check('copySlugBase: no chars before colon -> unchanged', CronjobUtils::copySlugBase(':link-index') === ':link-index');
check('copySlugBase: only first prefix stripped', CronjobUtils::copySlugBase('a:b:c') === 'b:c');
$slug = CronjobUtils::uniqueCopySlug(CronjobUtils::copySlugBase('linkenhancer:link-index'), static fn (string $s): bool => false);
check('copySlug: module job duplicate -> prefix-free slug', $slug === 'link-index-copy');

// --- resolvePayloadPath ------------------------------------------------------
$modules = $root . '/modules_v4';
check('payload: valid sibling resolved',
    str_ends_with((string) CliBootstrap::resolvePayloadPath($root . '/modules_v4/fakemod/cli/real-job.php', $modules), 'real-job.logic.php'));
check('payload: missing sibling -> null',
    CliBootstrap::resolvePayloadPath($root . '/modules_v4/fakemod/cli/nologic.php', $modules) === null);
check('payload: escapes modules_v4 -> null',
    CliBootstrap::resolvePayloadPath($root . '/modules_v4/fakemod/cli/../../index.php', $modules) === null);
check('payload: not under cli/ -> null',
    CliBootstrap::resolvePayloadPath($root . '/modules_v4/fakemod/offcli.php', $modules) === null);

rrm($root);

echo $failures === 0 ? "All job-spec tests passed.\n" : "{$failures} job-spec test(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
