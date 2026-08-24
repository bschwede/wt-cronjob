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

// Standalone test for JobRunner::buildArgv() (command whitelist + argument
// validation). No webtrees/DB needed: the root directory is a fixture.
//
// Run: php modules_v4/cronjob/tests/test-args-validator.php

require __DIR__ . '/../autoload.php';

use Schwendinger\Webtrees\Module\Cronjob\Services\JobRunner;

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

$root = sys_get_temp_dir() . '/cronjob-test-' . uniqid();
@mkdir($root . '/modules_v4/fakemod/cli', 0777, true);
@mkdir($root . '/modules_v4/fakemod2', 0777, true);
file_put_contents($root . '/modules_v4/fakemod/cli/real-job.php', '<?php // fixture' . "\n");
file_put_contents($root . '/modules_v4/fakemod2/not-cli.php', '<?php // fixture' . "\n");
file_put_contents($root . '/index.php', '<?php // fixture' . "\n");

/** @return array{argv: list<string>, error: string} */
function build(array $job, string $root): array {
    return JobRunner::buildArgv($job, $root);
}

// 1. Valid module script
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/real-job.php', 'args' => ''], $root);
check('valid module script', $built['error'] === '' && count($built['argv']) === 2 && str_ends_with($built['argv'][1], 'modules_v4/fakemod/cli/real-job.php'));

// 2. Non-existing module script
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/missing.php', 'args' => ''], $root);
check('missing module script rejected', $built['error'] !== '');

// 3. Path escape via ..
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/../not-cli.php', 'args' => ''], $root);
check('path escape via .. rejected', $built['error'] !== '');

// 4. Path outside cli/
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod2/not-cli.php', 'args' => ''], $root);
check('path outside cli/ rejected', $built['error'] !== '');

// 5. Core allowlisted command
$built = build(['command_type' => 'core', 'command' => 'tree-list', 'args' => ''], $root);
check('core allowlisted command', $built['error'] === '' && count($built['argv']) === 3 && str_ends_with($built['argv'][1], 'index.php') && $built['argv'][2] === 'tree-list');

// 6. Core command not allowlisted
$built = build(['command_type' => 'core', 'command' => 'db-migrate', 'args' => ''], $root);
check('core non-allowlisted command rejected', $built['error'] !== '');

// 7. site-setting without --list
$built = build(['command_type' => 'core', 'command' => 'site-setting', 'args' => ''], $root);
check('site-setting without --list rejected', $built['error'] !== '');

// 8. site-setting with --list
$built = build(['command_type' => 'core', 'command' => 'site-setting', 'args' => '--list'], $root);
check('site-setting with --list accepted', $built['error'] === '');

// 9. Shell metacharacters in args
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/real-job.php', 'args' => '; rm -rf /'], $root);
check('shell metacharacters rejected', $built['error'] !== '');

// 10. Command substitution in args
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/real-job.php', 'args' => '$(whoami)'], $root);
check('command substitution rejected', $built['error'] !== '');

// 11. Valid option=value arg
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/real-job.php', 'args' => '--limit=5000'], $root);
check('option=value accepted', $built['error'] === '' && end($built['argv']) === '--limit=5000');

// 12. Valid plain value arg
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/real-job.php', 'args' => '123'], $root);
check('plain value accepted', $built['error'] === '' && end($built['argv']) === '123');

// 13. More than 10 args
$many = implode(' ', array_fill(0, 11, 'x'));
$built = build(['command_type' => 'module', 'command' => 'modules_v4/fakemod/cli/real-job.php', 'args' => $many], $root);
check('more than 10 args rejected', $built['error'] !== '');

// 14. Empty command
$built = build(['command_type' => 'module', 'command' => '  ', 'args' => ''], $root);
check('empty command rejected', $built['error'] !== '');

// 15. Unknown command type
$built = build(['command_type' => 'shell', 'command' => '/bin/ls', 'args' => ''], $root);
check('unknown command type rejected', $built['error'] !== '');

// Cleanup
@unlink($root . '/modules_v4/fakemod/cli/real-job.php');
@unlink($root . '/modules_v4/fakemod2/not-cli.php');
@unlink($root . '/index.php');
@rmdir($root . '/modules_v4/fakemod/cli');
@rmdir($root . '/modules_v4/fakemod');
@rmdir($root . '/modules_v4/fakemod2');
@rmdir($root . '/modules_v4');
@rmdir($root);

if ($failures > 0) {
    echo "\n{$failures} test(s) FAILED\n";
    exit(1);
}
echo "\nAll args-validator tests passed.\n";
exit(0);
