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
use Schwendinger\Webtrees\Module\Cronjob\Services\CommandCatalogService;
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
@mkdir($root . '/modules_v4/fakemod3/cli', 0777, true);
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
file_put_contents($root . '/modules_v4/fakemod3/cron-jobs.php',
    '<?php return ['
    . ' "jobs" => [ [ "name" => "ev-demo", "trigger_type" => "event", "event_name" => "index-dirty",'
    . '  "command_type" => "module", "command" => "modules_v4/fakemod3/cli/real-job.php" ] ],'
    . ' "events" => [ [ "name" => "index-dirty", "description" => "Link index needs a rebuild",'
    . '  "payload" => [ "tree" ] ] ],'
    . ' "commands" => [ [ "command" => "modules_v4/fakemod3/cli/real-job.php",'
    . '  "command_type" => "module", "description" => "Run the demo",'
    . '  "params" => [ [ "name" => "--force", "optional" => true, "description" => "bypass cooldown" ] ] ] ]'
    . '];' . "\n");
file_put_contents($root . '/modules_v4/fakemod3/cli/real-job.php', '<?php // stub' . "\n");

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

$r = ScheduleService::validateJobSpec([
    'name' => 'on-dirty', 'trigger_type' => 'event', 'event_name' => 'linkenhancer:index-dirty',
    'command_type' => 'module', 'command' => $command,
], $root);
check('event job: namespaced event_name kept as-is', $r['errors'] === [] && $r['spec']['event_name'] === 'linkenhancer:index-dirty');

$r = ScheduleService::validateJobSpec([
    'name' => 'on-dirty', 'trigger_type' => 'event', 'event_name' => 'a:b:c',
    'command_type' => 'module', 'command' => $command,
], $root);
check('event job: double-colon event_name rejected', $r['errors'] !== []);

// --- validateEventSpec (§11 event announcements) -----------------------------
$r = ScheduleService::validateEventSpec(['name' => 'index-dirty', 'description' => 'Index needs a rebuild', 'payload' => ['tree', 'limit']]);
check('eventSpec: valid', $r['errors'] === [] && $r['spec'] === ['name' => 'index-dirty', 'description' => 'Index needs a rebuild', 'payload' => ['tree', 'limit']]);
$r = ScheduleService::validateEventSpec(['name' => 'index-dirty']);
check('eventSpec: description/payload optional', $r['errors'] === [] && $r['spec']['description'] === '' && $r['spec']['payload'] === []);
$r = ScheduleService::validateEventSpec(['name' => 'Bad Name']);
check('eventSpec: non-slug name rejected', $r['errors'] !== []);
$r = ScheduleService::validateEventSpec(['name' => '']);
check('eventSpec: empty name rejected', $r['errors'] !== []);
$r = ScheduleService::validateEventSpec(['name' => 'x', 'description' => str_repeat('a', 256)]);
check('eventSpec: long description rejected', $r['errors'] !== []);
$r = ScheduleService::validateEventSpec(['name' => 'x', 'payload' => 'nope']);
check('eventSpec: non-list payload rejected', $r['errors'] !== []);
$r = ScheduleService::validateEventSpec(['name' => 'x', 'payload' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i']]);
check('eventSpec: >8 payload keys rejected', $r['errors'] !== []);
$r = ScheduleService::validateEventSpec(['name' => 'x', 'payload' => ['a', '', 3]]);
check('eventSpec: payload normalized to strings, empty dropped', $r['errors'] === [] && $r['spec']['payload'] === ['a', '3']);

// --- validateCommandSpec (§12 command announcements) -------------------------
$r = ScheduleService::validateCommandSpec(['command' => $command, 'command_type' => 'module', 'description' => 'Run it', 'params' => [['name' => '--force', 'optional' => true, 'description' => 'bypass']]]);
check('cmdSpec: valid module command', $r['errors'] === [] && $r['spec']['command_type'] === 'module' && $r['spec']['description'] === 'Run it' && count($r['spec']['params']) === 1);
check('cmdSpec: param normalized (optional default)', $r['spec']['params'][0] === ['name' => '--force', 'optional' => true, 'default' => null, 'description' => 'bypass']);
$r = ScheduleService::validateCommandSpec(['command' => 'tree-export']);
check('cmdSpec: command_type derived for core command', $r['errors'] === [] && $r['spec']['command_type'] === 'core');
$r = ScheduleService::validateCommandSpec(['command' => '']);
check('cmdSpec: empty command rejected', $r['errors'] !== []);
$r = ScheduleService::validateCommandSpec(['command' => str_repeat('a', 256)]);
check('cmdSpec: command >255 rejected', $r['errors'] !== []);
$r = ScheduleService::validateCommandSpec(['command' => 'random-thing']);
check('cmdSpec: neither module path nor core -> rejected', $r['errors'] !== []);
$r = ScheduleService::validateCommandSpec(['command' => $command, 'description' => str_repeat('a', 256)]);
check('cmdSpec: long description rejected', $r['errors'] !== []);
$r = ScheduleService::validateCommandSpec(['command' => $command, 'params' => 'nope']);
check('cmdSpec: non-list params rejected', $r['errors'] !== []);
$tooMany = [];
for ($i = 0; $i < 9; $i++) {
    $tooMany[] = ['name' => '--o' . $i];
}
$r = ScheduleService::validateCommandSpec(['command' => $command, 'params' => $tooMany]);
check('cmdSpec: >8 params rejected', $r['errors'] !== []);
$r = ScheduleService::validateCommandSpec(['command' => $command, 'params' => [['name' => ''], ['name' => '--ok'], ['name' => 'bad name']]]);
check('cmdSpec: malformed params dropped silently', $r['errors'] === [] && $r['spec']['params'] === [['name' => '--ok', 'optional' => true, 'default' => null, 'description' => '']]);
$r = ScheduleService::validateCommandSpec(['command' => $command, 'params' => [['name' => '--tree', 'optional' => false, 'default' => 'I123']]]);
check('cmdSpec: required + default kept', $r['spec']['params'][0] === ['name' => '--tree', 'optional' => false, 'default' => 'I123', 'description' => '']);

// --- isValidEventName (§11 naming scheme) ------------------------------------
check('eventName: plain slug valid', CronjobUtils::isValidEventName('index-dirty') === true);
check('eventName: namespaced valid', CronjobUtils::isValidEventName('linkenhancer:index-dirty') === true);
check('eventName: route domain valid', CronjobUtils::isValidEventName('_route:edit-note-object') === true);
check('eventName: pseudo domain valid', CronjobUtils::isValidEventName('cronjob:gedcom-changed') === true);
check('eventName: double colon rejected', CronjobUtils::isValidEventName('a:b:c') === false);
check('eventName: leading colon rejected', CronjobUtils::isValidEventName(':x') === false);
check('eventName: trailing colon rejected', CronjobUtils::isValidEventName('x:') === false);
check('eventName: uppercase rejected', CronjobUtils::isValidEventName('Mod:Name') === false);
check('eventName: 64 chars incl. colon valid', CronjobUtils::isValidEventName(str_repeat('a', 31) . ':' . str_repeat('b', 32)) === true);
check('eventName: 65 chars rejected', CronjobUtils::isValidEventName(str_repeat('a', 32) . ':' . str_repeat('b', 33)) === false);

// --- loadManifestFile --------------------------------------------------------
$manifest = CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod/cron-jobs.php');
check('manifest: plain list -> jobs', is_array($manifest) && count($manifest['jobs']) === 1 && ($manifest['jobs'][0]['name'] ?? '') === 'demo' && $manifest['events'] === [] && $manifest['commands'] === []);
$manifest = CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod3/cron-jobs.php');
check('manifest: assoc form -> jobs', count($manifest['jobs']) === 1 && ($manifest['jobs'][0]['name'] ?? '') === 'ev-demo');
check('manifest: assoc form -> events', count($manifest['events']) === 1 && ($manifest['events'][0]['name'] ?? '') === 'index-dirty' && ($manifest['events'][0]['payload'] ?? null) === ['tree']);
check('manifest: assoc form -> commands', count($manifest['commands']) === 1 && ($manifest['commands'][0]['command'] ?? '') === 'modules_v4/fakemod3/cli/real-job.php' && count($manifest['commands'][0]['params']) === 1);
check('manifest: non-array returns empty', CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod2/no-array.php') === ['jobs' => [], 'events' => [], 'commands' => []]);
check('manifest: throwing returns empty', CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod2/cron-jobs.php') === ['jobs' => [], 'events' => [], 'commands' => []]);
check('manifest: missing file returns empty', CronjobUtils::loadManifestFile($root . '/modules_v4/fakemod2/nope.php') === ['jobs' => [], 'events' => [], 'commands' => []]);

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

// --- isValidJobName ----------------------------------------------------------
check('jobName: plain slug valid', CronjobUtils::isValidJobName('link-index') === true);
check('jobName: digits/underscore/dash valid', CronjobUtils::isValidJobName('a0_b-9') === true);
check('jobName: leading dash rejected', CronjobUtils::isValidJobName('-bad') === false);
check('jobName: uppercase rejected', CronjobUtils::isValidJobName('BadName') === false);
check('jobName: 64 chars valid', CronjobUtils::isValidJobName(str_repeat('a', 64)) === true);
check('jobName: 65 chars rejected', CronjobUtils::isValidJobName(str_repeat('a', 65)) === false);
check('jobName: module key rejected by default', CronjobUtils::isValidJobName('cronjob:pseudo-events') === false);
check('jobName: module key allowed when permitted', CronjobUtils::isValidJobName('cronjob:pseudo-events', true) === true);
check('jobName: malformed module key rejected even when permitted', CronjobUtils::isValidJobName('cronjob:', true) === false);
check('jobName: module key with bad prefix rejected', CronjobUtils::isValidJobName(':pseudo-events', true) === false);
check('jobName: module key at 64 chars incl. colon allowed', CronjobUtils::isValidJobName(str_repeat('a', 31) . ':' . str_repeat('b', 32), true) === true);
check('jobName: module key over 64 chars rejected', CronjobUtils::isValidJobName(str_repeat('a', 32) . ':' . str_repeat('b', 32), true) === false);

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

// --- mergeCatalog (§12 command inventory, pure) -----------------------------
$core = [
    ['command' => 'tree-list', 'command_type' => 'core', 'source' => 'core', 'description' => 'List trees.', 'params' => []],
    ['command' => 'site-setting', 'command_type' => 'core', 'source' => 'core', 'description' => 'Site settings.', 'params' => [['name' => '--list', 'optional' => true]]],
];
$announced = [
    ['module' => 'fakemod', 'command' => 'modules_v4/fakemod/cli/real-job.php', 'command_type' => 'module', 'description' => 'Demo', 'params' => [['name' => '--force', 'optional' => true]]],
];
$globbed = [
    ['command' => 'modules_v4/fakemod/cli/real-job.php', 'module' => 'fakemod'],
    ['command' => 'modules_v4/fakemod/cli/internal.php', 'module' => 'fakemod'],    // fakemod announced -> suppressed
    ['command' => 'modules_v4/fakemod2/cli/other.php', 'module' => 'fakemod2'],      // not announced -> included
];
$catalog = CommandCatalogService::mergeCatalog($core, $announced, $globbed);
check('mergeCatalog: core included', isset($catalog['tree-list']) && $catalog['tree-list']['source'] === 'core');
check('mergeCatalog: core params normalized', ($catalog['site-setting']['params'][0]['name'] ?? '') === '--list' && ($catalog['site-setting']['params'][0]['optional'] ?? null) === true);
check('mergeCatalog: announced included with params + source', isset($catalog['modules_v4/fakemod/cli/real-job.php']) && $catalog['modules_v4/fakemod/cli/real-job.php']['source'] === 'fakemod' && count($catalog['modules_v4/fakemod/cli/real-job.php']['params']) === 1);
check('mergeCatalog: announcing module globbed script suppressed', !isset($catalog['modules_v4/fakemod/cli/internal.php']));
check('mergeCatalog: non-announcing module globbed script included', isset($catalog['modules_v4/fakemod2/cli/other.php']) && $catalog['modules_v4/fakemod2/cli/other.php']['command_type'] === 'module');
check('mergeCatalog: count (2 core + 1 announced + 1 globbed)', count($catalog) === 4);
$dup = CommandCatalogService::mergeCatalog(
    [['command' => 'tree-list', 'command_type' => 'core', 'source' => 'core', 'description' => 'CORE', 'params' => []]],
    [['module' => 'm', 'command' => 'tree-list', 'command_type' => 'module', 'description' => 'ANN', 'params' => []]],
    []
);
check('mergeCatalog: first source wins (core over announced)', $dup['tree-list']['source'] === 'core' && $dup['tree-list']['description'] === 'CORE' && count($dup) === 1);

rrm($root);

echo $failures === 0 ? "All job-spec tests passed.\n" : "{$failures} job-spec test(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
