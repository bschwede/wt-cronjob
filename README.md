# webtrees module Cronjob

A **cron job scheduler service** for webtrees: schedule maintenance/batch jobs with
standard cron expressions, watch their run history in the admin UI, and trigger them
manually. The module is the *service*; the OS (classic cron or a systemd timer) only
has to call one small script once per minute.

- **Job registry + run history** in two own tables (`cj_job`, `cj_run`)
- **Admin UI** (control panel): job list, create/edit form, run history, run now, enable/disable, delete
- **Isolated execution**: every job runs as a separate child PHP process (`proc_open`, no shell), with per-job timeout and captured output
- **Offline awareness**: while `data/offline.txt` exists (e.g. during a webtrees update) the tick is skipped *before any database access* - no child process starts while migrations and code are in flux
- **No core changes, no changes to other modules**

## Requirements

- webtrees 2.2.x (PHP 8.3+)
- PHP CLI with `proc_open` available (standard)
- A trigger: classic cron, a systemd timer, **or** the built-in watch daemon
  (no OS timer needed - see "Trigger installation")

## Installation

1. Copy the module folder to `modules_v4/cronjob/` (ZIP install works too).
   The cron library ships inside the module - nothing to fetch.
2. Open webtrees once (any page as an admin) so the module's `boot()` creates its tables.
3. Install the trigger (classic cron **or** systemd timer) - the module's admin page
   (`Control panel → Cron Job Scheduler`) generates both with the paths of *this*
   installation already filled in.
4. Create your first job in the admin UI.

### Bundled dependency

The module bundles exactly one external library:
[`dragonmantank/cron-expression`](https://github.com/dragonmantank/cron-expression)
**v3.6.0** (cron parsing, zero dependencies of its own) - it ships in
`vendor/dragonmantank/cron-expression/`, pinned by `composer.lock`. No
fetch step is required; a target box without internet works out of the box.

The runtime loader is the hand-written `modules_v4/cronjob/autoload.php`
(a small PSR-4 mapper for the `Cron\` prefix) - no second Composer runtime is
involved. If the vendor folder is ever missing (partial install), the admin UI
shows a warning and the tick skips cron evaluation (everything else still works).

> **Prefix note:** the loader maps the `Cron\` namespace to this module's vendor
> folder. If webtrees (core) ever vendors the same library, the core autoloader is
> registered first and wins silently - the APIs are identical (same library), so
> this is harmless. The same applies to a second custom module bundling `Cron\`.

To update the library later (on a machine with internet + composer):

```bash
cd modules_v4/cronjob
composer update --no-dev
```

then re-bundle `composer.lock` plus `vendor/dragonmantank/cron-expression/`
(`src/` plus its own `composer.json`) into the module.

## Usage

### Creating a job

`Control panel → Cron Job Scheduler → New Job`:

| Field | Meaning |
| :--- | :--- |
| **Name (slug)** | technical identifier, `a-z 0-9 _ -` |
| **Title** | human readable name |
| **Cron expression** | standard 5-field cron (`*/30 * * * *`), or a macro (`@daily`, `@weekly`, ...). **Times are UTC** (the webtrees server time basis - the same basis the core uses for all timestamps). The form previews the next 5 runs. |
| **Command** | a `modules_v4/<module>/cli/<script>.php` path (discovered scripts are offered as suggestions) or an allowlisted core command: `tree-export`, `tree-list`, `user-list`, `site-setting` |
| **Arguments** | plain options/values only (e.g. `--limit=5000`), max 10 tokens |
| **Timeout** | 30 - 3600 s |

The **Run now** button queues the job for the next tick (≤ 60 s). The **History**
page shows status, exit code, duration and the captured output (last 64 KB) of the
last 25 runs per job (plus a 30-day global retention window).

### Trigger installation (once)

Pick **one** of the options shown in the module's admin page (classic cron, a
systemd timer, or the built-in watch daemon):

**Option A - classic cron** (one line in the web server user's crontab, runs every
minute):

```
* * * * * cd /path/to/webtrees && php modules_v4/cronjob/cli/tick.php >> /path/to/webtrees/data/cronjob-tick.log 2>&1
```

**Option B - systemd timer** (two unit files, also shown in the admin page):

```ini
# /etc/systemd/system/cronjob-tick.service
[Unit]
Description=webtrees cronjob module tick (runs due maintenance jobs)

[Service]
Type=oneshot
User=<webserver-user>
WorkingDirectory=/path/to/webtrees
ExecStart=/usr/bin/php modules_v4/cronjob/cli/tick.php
```

```ini
# /etc/systemd/system/cronjob-tick.timer
[Unit]
Description=Run the webtrees cronjob tick every minute

[Timer]
OnBootSec=1min
OnUnitActiveSec=1min
Persistent=true

[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now cronjob-tick.timer
```

**Option C - watch daemon** (no OS timer at all): click *Start watch* on the
admin page. A resident process then runs the tick every 60 seconds, and a
page-load watchdog respawns it if it dies. Use it when the host has no cron and
no systemd access.

<details>
<summary>How the watch daemon works</summary>

- **Opt-in:** nothing runs until you click *Start watch*; *Stop watch* removes it
  (the daemon exits within about a minute and is not respawned).
- **Liveness:** the daemon holds `data/cronjob-watch.lock` for its whole life.
  The watchdog (in the module's `boot()`, on every page load) respawns it only
  when that lock is free, the watch is enabled, the site is online, and at
  least 5 minutes have passed since the last spawn attempt. With the watch
  disabled the watchdog costs a single `file_exists()` per page load.
- **Idle & offline:** while the site is offline it stays idle (no tick);
  otherwise it ticks every 60 s. All state lives in `data/cronjob-watch.*`.
- **Self-update:** if the module is re-deployed the daemon detects the changed
  files and exits, so a fresh daemon picks up the new code.
- **Works under php-fpm / mod_php:** the daemon is spawned with a resolved PHP
  *CLI* interpreter (under the web SAPI `PHP_BINARY` is empty or the server
  binary, which cannot run scripts), so *Start watch* from the browser works.
- **Hard limits (honest):** without `posix_setsid`/`pcntl_fork` the daemon stays
  in the web server's process group - a full web server / FPM **master** restart
  kills it, and the watchdog brings it back on the next page load (self-healing).
  A plain FPM **worker** recycle does not kill it. Disabling the *module* does
  not stop the daemon (use *Stop watch*); the ticks simply no-op.
- It is one resident PHP process that sleeps between ticks (negligible CPU).
  Use this **or** the OS trigger, not both.

</details>

### CLI

```bash
php modules_v4/cronjob/cli/tick.php              # run all due jobs
php modules_v4/cronjob/cli/tick.php --dry-run    # show what would run
php modules_v4/cronjob/cli/tick.php --job=<name> # run one specific job now
php modules_v4/cronjob/cli/tick.php --strict     # exit 1 if any job failed (monitoring)
php modules_v4/cronjob/cli/tick.php --full-output # echo full job output to stdout (debugging!)
php modules_v4/cronjob/cli/watch.php             # run the resident watch daemon (normally started via "Start watch")
```

Exit codes: `0` = tick ran (job failures are recorded in the DB), `1` = environment
problem or (with `--strict`) at least one job failed.

**Log hygiene:** stdout carries summary lines only (`job <name>: ok (exit 0, 123 ms)`).
Full job output lives in the run history (admin-only). Job output can contain
personal data (`user-list`) or all site settings (`site-setting --list`) - only use
`--full-output` for interactive debugging, and never redirect it into a
world-readable location (e.g. `data/`, which is inside the web root by default).

### Acceptance test

```bash
php modules_v4/cronjob/cli/smoke-job.php            # prints ok, exit 0
php modules_v4/cronjob/cli/smoke-job.php --sleep=10 # stays alive 10 s (timeout testing)
```

Create a job with command `modules_v4/cronjob/cli/smoke-job.php`, hit **Run now**,
wait a minute - the history page should show a green `ok` row.

## Security

- **No shell, ever.** Commands are executed via `proc_open` with an argv array.
- **Command whitelist.** Module scripts must match `modules_v4/<module>/cli/<script>.php`,
  exist, and resolve (realpath) inside a `modules_v4/<module>/cli/` directory.
  Core commands are limited to the read-only/export allowlist
  (`tree-export`, `tree-list`, `user-list`, `site-setting --list`).
- **Argument validation.** Every argument token must be a plain option or value -
  no shell metacharacters, no spaces inside tokens, max 10 tokens.
- **Working directories.** Module scripts run from the webtrees root; core commands
  run from `data/`, because the core CLI writes relative to the CWD
  (`tree-export` produces `<tree>.ged`) - generated files must not end up in the
  web root.
- **No sensitive data in stdout.** The tick echoes summary lines only (see "Log
  hygiene" above); the full output stays in the admin-only run history.
- **UI actions** are all named `*Admin*` (admin-only, CSRF-protected like every
  webtrees form).
- **Kill switch:** disable the module in the module list - the tick then does nothing.
- The tick is single-instance (flock on `data/cronjob-tick.lock`), and a job's child
  process is hard-killed after its timeout (`SIGKILL`).

## CLI scripts & maintenance (conventions for future job scripts)

New job scripts in other modules must follow these conventions (linkenhancer's
`cli/` is the reference):

- Location `modules_v4/<module>/cli/`, invoked as
  `php modules_v4/<module>/cli/<script>.php` (working directory = webtrees root)
- **Start with** `require autoload.php` (the module's own) **and**
  `CliBootstrap::guard()` (or the module's own equivalent) - scripts in `modules_v4/`
  are reachable by URL and would be a data leak without the CLI guard
- Idempotent and incremental (`--limit`, `--since`), batched, never full re-runs
  where a delta is possible
- Exit code `0` on success (the tick records non-zero as `error`)
- No interactive input; everything via CLI options
- Template files are prefixed with `_` (excluded from job discovery)

## Job outlook (candidates for the first jobs)

| Job | Command | Cron (UTC) |
| :--- | :--- | :--- |
| Linkenhancer link-index update | `modules_v4/linkenhancer/cli/build-link-index.php --limit=5000` | `*/30 * * * *` |
| GEDCOM backup (tree export) | `tree-export <tree_name>` — writes `data/<tree_name>.ged`, **full personal data**; plan retention/cleanup of `data/*.ged` | `0 3 * * 0` |
| Config backup | `site-setting --list` — output lands in the run history (admin-only); for a file backup use a small module CLI script | `0 3 * * 0` |
| Smoke test | `modules_v4/cronjob/cli/smoke-job.php` | `0 4 * * *` |

## Roadmap (phase 2, not implemented)

- **Event-driven jobs**: webhook receiver (token protected, HMAC), event queue with
  retry/backoff, and polling pseudo-events (`tree_changed`, `media_added`, ...)
  - the core has no event system, so this is a DB-queue, not core hooks
- **E-Mail notifications** on job failure
- **Job self-registration**: modules advertise their CLI scripts via a module
  interface, so the form can offer typed jobs
- **Human-readable schedule display** ("every 30 minutes") as a UI add-on

## Tests

```bash
php modules_v4/cronjob/tests/test-args-validator.php  # command whitelist + arg validation (standalone)
php modules_v4/cronjob/tests/test-cron-wrapper.php    # cron semantics (skips cleanly without the bundled vendor)
php modules_v4/cronjob/tests/test-watch-service.php   # watch daemon logic: opt-in marker, liveness lock, cooldown (standalone)
```

## License

GPL-3.0-or-later, like webtrees itself.
