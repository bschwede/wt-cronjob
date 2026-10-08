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

namespace Schwendinger\Webtrees\Module\Cronjob\Services;

use Fisharebest\Webtrees\Webtrees;

use function date;
use function get_current_user;
use function implode;
use function realpath;
use function rtrim;
use function str_replace;

/**
 * Copy-paste trigger-installation blocks for the admin view:
 * one classic cron line, a systemd service/timer pair (both for Unix)
 * and a Windows Task Scheduler XML plus its import command (for
 * Windows hosts) - all derived from this installation (paths, PHP
 * binary). The admin view renders the pair matching PHP_OS_FAMILY.
 * Every interpolated path is double-quoted unconditionally (paths may
 * contain spaces, e.g. C:\Program Files\...); on Windows the cmd
 * wrapper uses /S for deterministic quote handling.
 */
final class TriggerInstallService {

    /**
     * @return array<string, string>
     */
    public static function triggerInstallBlocks(): array {
        $root = realpath(rtrim(str_replace('\\', '/', Webtrees::ROOT_DIR), '/'));
        $php  = str_replace('\\', '/', WatchService::phpBinary());
        $tick = 'modules_v4/cronjob/cli/tick.php';
        $log  = $root . '/data/cronjob/tick.log';

        // Windows Task Scheduler: native (backslash) paths, no normalization.
        // The cmd wrapper mirrors the cron line: working directory, ensure
        // data/cronjob exists (the redirect cannot create it), same log target.
        $root_ws = rtrim(str_replace('/', '\\', (string) $root), '\\');
        $php_ws  = str_replace('/', '\\', $php);
        $cmd_ws  = '/S /c "cd /d "' . $root_ws . '" && if not exist data\\cronjob mkdir data\\cronjob && "' . $php_ws . '" modules_v4\\cronjob\\cli\\tick.php cron:tick >> data\\cronjob\\tick.log 2>&1"';
        // XML-escape the element text: & first, then < and >.
        $args_ws = str_replace('&', '&amp;', $cmd_ws);
        $args_ws = str_replace('<', '&lt;', $args_ws);
        $args_ws = str_replace('>', '&gt;', $args_ws);

        $windows_task_xml = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Task version="1.2" xmlns="http://schemas.microsoft.com/windows/2004/02/mit/task">',
            '  <RegistrationInfo>',
            '    <Description>webtrees cronjob module tick (runs due maintenance jobs)</Description>',
            '  </RegistrationInfo>',
            '  <Triggers>',
            '    <CalendarTrigger>',
            // StartBoundary = now + ScheduleByRun: active right after import,
            // repeating every minute for as long as the machine is up.
            '      <StartBoundary>' . date('Y-m-d\TH:i:s') . '</StartBoundary>',
            '      <Enabled>true</Enabled>',
            '      <ScheduleByRun>true</ScheduleByRun>',
            '      <RepeatedTask>',
            '        <Interval>PT1M</Interval>',
            '        <Duration>PT0H</Duration>',
            '      </RepeatedTask>',
            '    </CalendarTrigger>',
            '  </Triggers>',
            '  <Principals>',
            // InteractiveToken: runs as the logged-on user, no password for
            // the copy-paste import. A server without a logged-in session
            // must switch the task to "run whether the user is logged on or
            // not" (task properties -> General, requires the password).
            '    <Principal id="Author">',
            '      <LogonType>InteractiveToken</LogonType>',
            '    </Principal>',
            '  </Principals>',
            '  <Settings>',
            // IgnoreNew: matches the module's own tick.lock single-instance guard.
            '    <MultipleInstancesPolicy>IgnoreNew</MultipleInstancesPolicy>',
            '    <DisallowStartIfOnBatteries>false</DisallowStartIfOnBatteries>',
            '    <StopIfGoingOnBatteries>false</StopIfGoingOnBatteries>',
            '    <AllowHardTerminate>true</AllowHardTerminate>',
            // StartWhenAvailable: one tick after a sleep/off gap - safe, the
            // tick is idempotent (coalescing, no catch-up flood).
            '    <StartWhenAvailable>true</StartWhenAvailable>',
            '    <RunOnlyIfNetworkAvailable>false</RunOnlyIfNetworkAvailable>',
            '    <AllowStartOnDemand>true</AllowStartOnDemand>',
            '    <Enabled>true</Enabled>',
            '    <Hidden>false</Hidden>',
            '    <RunOnlyIfIdle>false</RunOnlyIfIdle>',
            '    <WakeToRun>false</WakeToRun>',
            // PT0H = no scheduler-side limit; the module applies its own
            // per-job timeouts.
            '    <ExecutionTimeLimit>PT0H</ExecutionTimeLimit>',
            '    <Priority>7</Priority>',
            '  </Settings>',
            '  <Actions Context="Author">',
            '    <Exec>',
            '      <Command>cmd.exe</Command>',
            '      <Arguments>' . $args_ws . '</Arguments>',
            '    </Exec>',
            '  </Actions>',
            '</Task>',
        ]);

        return [
            // mkdir -p: the redirect target must exist even when the first
            // cron run happens before the first page load.
            'cron_line'   => '* * * * * cd "' . $root . '" && mkdir -p data/cronjob && "' . $php . '" ' . $tick . ' cron:tick >> "' . $log . '" 2>&1',
            'service_unit' => implode("\n", [
                '[Unit]',
                'Description=webtrees cronjob module tick (runs due maintenance jobs)',
                '',
                '[Service]',
                'Type=oneshot',
                'User=' . get_current_user(),
                'WorkingDirectory="' . $root . '"',
                'ExecStart="' . $php . '" ./' . $tick . ' cron:tick',
            ]),
            'timer_unit' => implode("\n", [
                '[Unit]',
                'Description=Run the webtrees cronjob tick every minute',
                '',
                '[Timer]',
                'OnBootSec=1min',
                'OnCalendar=*:*:00',
                'Persistent=true',
                '',
                '[Install]',
                'WantedBy=timers.target',
            ]),
            'install_commands' => implode("\n", [
                '# as root, once:',
                'sudo cp wt-cronjob-tick.service wt-cronjob-tick.timer /etc/systemd/system/',
                'sudo systemctl daemon-reload',
                'sudo systemctl enable --now wt-cronjob-tick.timer',
            ]),
            'windows_task_xml' => $windows_task_xml,
            'windows_import' => implode("\n", [
                ':: save the XML above as wt-cronjob-tick.xml (UTF-8), then, once, in an elevated prompt:',
                'schtasks /Create /F /XML wt-cronjob-tick.xml',
                ':: GUI alternative: Task Scheduler -> Actions -> "Import Task..." -> wt-cronjob-tick.xml',
                ':: verify:',
                'schtasks /Query /TN "wt-cronjob-tick" /FO LIST',
            ]),
        ];
    }
}
