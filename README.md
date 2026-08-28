# webtrees module Cronjob

A **cron job scheduler service** for webtrees: schedule maintenance/batch jobs with
standard cron expressions, watch their run history in the admin UI, and trigger them
manually. The module is the *service*; the OS (classic cron or a systemd timer) only
has to call one small script once per minute.

- **Job registry + run history** in two own tables (`cj_job`, `cj_run`)
- **Admin UI** (control panel): job list, create/edit form, run history, run now, enable/disable, delete
- **Isolated execution**: every job runs as a separate child PHP process (`proc_open`, no shell), with per-job timeout and captured output
- **Self-registration**: other modules can advertise their jobs (a `cron-jobs.php` manifest or a `getCronJobs()` method); they appear here, created disabled and ready to enable
- **Event-driven jobs**: jobs can fire on events instead of a schedule - queued by a token-protected **webhook**, by a direct `EventQueue::push()` call from other modules, or by the built-in **pseudo-event** pollers (GEDCOM change, new media, new user)
- **Failure notification**: per-job opt-in to alert the site's administrator accounts (internal message and/or e-mail, per each admin's own preference) when a job fails
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
| **Name (slug)** | technical identifier, `a-z 0-9 _ -`. **Read-only for module-offered jobs** (their `<module>:<name>` key is fixed and cannot be retargeted); editable for jobs you create yourself. |
| **Title** | human readable name |
| **Triggers** | one or more **time triggers** (cron schedules) and/or one or more **event triggers** (runs when a named event is queued) - the job fires when *any* of its triggers fires. At least one trigger is required. Rows can be added/removed in the form. See "Event-driven jobs". |
| **Cron expression** | (time trigger) standard 5-field cron (`*/30 * * * *`), or a macro (`@daily`, `@weekly`, ...). Multiple cron rows are possible (e.g. hourly *and* a daily full run). **Times are UTC** (the webtrees server time basis - the same basis the core uses for all timestamps). The form previews the next 3 runs per expression. |
| **Event name** | (event trigger) the event this job reacts to, e.g. `linkenhancer:index-dirty`. Multiple event rows are possible. Suggestions come from the **event catalog** (module announcements, built-in pseudo-events `cronjob:…`, route events `_route:…` and listened names); any own name (webhook / direct module push) works too. Slug or `<domain>:slug`, max 64 chars. |
| **Command** | a `modules_v4/<module>/cli/<script>.php` path or an allowlisted core command: `tree-export`, `tree-list`, `user-list`, `site-setting`. Suggestions come from the **command catalog** (core allowlist + module announcements + module scripts). When the entered command matches an announced command, its **available parameters** are listed as a hint under the field. |
| **Arguments** | plain options/values only (e.g. `--limit=5000`), max 10 tokens |
| **Timeout** | 30 - 3600 s |
| **Notify on failure** | opt-in: alert the administrator accounts when this job fails |

The **Run now** button queues the job for the next tick (≤ 60 s). For a **disabled**
job the queued run happens at the first tick *after* it is (re-)enabled - the button
tooltip and the flash message say so (there is deliberately no guard: disabling the
job right after the click reaches the same state anyway). The **History** page shows
status, exit code, duration, the **trigger that fired the run** (the due cron
expression(s) or the event name) and the captured output (last 64 KB) of the last 25
runs per job (plus a 30-day global retention window).

The job table is a **client-side DataTable** (search box, sortable columns, paging,
state saved in the browser) - no extra setup needed. The **Enabled** and **Notify**
columns can be toggled in place (click the ✓/✗ button) without opening the form, and
the **Timeout** column shows each job's per-run timeout in seconds. Per row there are additional
actions: **Duplicate** opens the create form prefilled with a copy of the job
(new `-copy` slug, starts disabled; for module-offered jobs the `<module>:` name
prefix is removed, since the colon cannot be entered in the form), and **Reset**
(only for jobs offered by a module manifest) restores the module's currently-offered
defaults. If a save fails validation, the form is re-rendered with all entered
values kept (plus the error messages) - nothing you typed is lost.

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
ExecStart=/usr/bin/php modules_v4/cronjob/cli/tick.php cron:tick
```

```ini
# /etc/systemd/system/cronjob-tick.timer
[Unit]
Description=Run the webtrees cronjob tick every minute

[Timer]
OnBootSec=1min
OnCalendar=*:*:00
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
  The watchdog (a per-request module middleware, on every page load) respawns it only
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
- **Detached:** the daemon is spawned with `setsid` into its own session, so it
  survives an FPM **worker** recycle and even an FPM **master** restart; only
  stopping the whole web service / container, or *Stop watch*, ends it (the
  watchdog otherwise self-heals on the next page load). Disabling the *module*
  does not stop the daemon (use *Stop watch*); the ticks simply no-op.
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
- **Event-queue coalescing (1.1.0).** A flood of identical events (e.g. many rapid
  edits on a route event) cannot amplify into one job run per queued row: the tick
  drains at most **one event per event name** (the newest) and drops the name's
  superseded duplicates, and the event drain stops after a 60 s wall-clock budget -
  a saturated queue then simply continues with the next tick. This relies on the
  job-script convention (idempotent/incremental) stated above.
- **Webhook token header-only (1.1.0).** The `?token=<token>` query fallback was
  removed - a token in the URL would end up in access logs and proxy logs. Send the
  token exclusively in the `X-Cronjob-Token` header (breaking change for senders
  that used the query parameter).

## CLI scripts & maintenance (conventions for future job scripts)

New job scripts in other modules must follow these conventions (linkenhancer's
`cli/` is the reference):

- Location `modules_v4/<module>/cli/`, invoked as
  `php modules_v4/<module>/cli/<script>.php` (working directory = webtrees root)
- **Start with** `require autoload.php` (the module's own) **and**
  `CliBootstrap::guard()` (or the module's own equivalent) - scripts in `modules_v4/`
  are reachable by URL and would be a data leak without the CLI guard
  - if the module already depends on cronjob, this bootstrap can instead be
    delegated to the shared **W1 wrapper** (see "Using the W1 wrapper" below)
- Idempotent and incremental (`--limit`, `--since`), batched, never full re-runs
  where a delta is possible
- Exit code `0` on success (the tick records non-zero as `error`)
- No interactive input; everything via CLI options
- Template files are prefixed with `_` (excluded from job discovery)

## Offering jobs to cronjob (self-registration)

Other modules can *advertise* their scheduled jobs so they show up in this module's
admin UI without anyone editing the registry by hand. There are two ways (a module
may use both); both are discovered automatically on each tick, only for **enabled**
modules:

### 1. Manifest file (recommended)

`modules_v4/<module>/cron-jobs.php` that **returns an array of job specs**:

```php
<?php
// modules_v4/linkenhancer/cron-jobs.php
declare(strict_types=1);

return [
    [
        'name'         => 'link-index',
        'title'        => 'Update the link index',
        'triggers'     => [
            ['type' => 'time', 'cron' => '*/30 * * * *'],
        ],
        'command_type' => 'module',
        'command'      => 'modules_v4/linkenhancer/cli/build-link-index.php',
        'args'         => '--limit=5000',
        // 'enabled'      => false,  // default: created disabled (opt-in)
        // 'timeout_sec'  => 300,    // default
    ],
];
```

Translatable `title` / `description` literals in a manifest should be wrapped in
`I18nMark::translate()` (identity marker, see [Translation (i18n)](#translation-i18n))
so `xgettext` picks them up - translation happens at render time, never at manifest
load.

### 2. Marker method

`getCronJobs(): array` on the module's `module.php` class, returning the same shape.
cronjob detects it with `method_exists()` - the module does **not** implement any
cronjob interface (so an absent cronjob module is harmless).

### Announcing events

The same manifest can also **announce events** your module emits (so they show up in
the event catalog and the job form's suggestions). Instead of a plain job list, the
manifest may return an associative array with optional `jobs` and `events` lists (a
plain list still means "jobs only"):

```php
<?php
// modules_v4/linkenhancer/cron-jobs.php
declare(strict_types=1);

return [
    'jobs' => [
        [
            'name'     => 'link-index',
            'triggers' => [
                ['type' => 'time', 'cron' => '*/30 * * * *'],
                // A job may combine several triggers:
                // ['type' => 'event', 'event' => 'linkenhancer:index-dirty'],
            ],
            'command_type' => 'module',
            'command'      => 'modules_v4/linkenhancer/cli/build-link-index.php',
        ],
        [
            'name'     => 'reindex-on-dirty',
            'triggers' => [
                ['type' => 'event', 'event' => 'linkenhancer:index-dirty'], // listen to your own event
            ],
            'command_type' => 'module',
            'command'      => 'modules_v4/linkenhancer/cli/build-link-index.php',
        ],
    ],
    'events' => [
        ['name' => 'index-dirty', 'description' => 'The link index is out of date.', 'payload' => ['tree']],
    ],
];
```

- An announced event is namespaced **`<module>:<name>`** (like the offered job keys).
- An event trigger's `event` value that carries no colon is auto-namespaced
  `<module>:<event>`; a name that already has a colon (e.g.
  `cronjob:gedcom-changed`) is kept as-is.
- Equivalent marker method: `getModuleEvents(): array` (same event-spec shape).

**Event spec fields**

| Key | Required | Meaning |
| :--- | :--- | :--- |
| `name` | yes | slug `a-z 0-9 _ -` (stored as `<module>:<name>`) |
| `description` | no | shown in the event catalog (translation key, max 255) |
| `payload` | no | list of payload parameter names (max 8), shown in the catalog |

### Announcing commands

The same manifest can also **announce commands** a job may run, with their available
parameters. A plain manifest list is still "jobs only"; to announce commands the
manifest returns the associative form with a `commands` list:

```php
<?php
// modules_v4/linkenhancer/cron-jobs.php
declare(strict_types=1);

return [
    'jobs' => [ /* … */ ],
    'commands' => [
        [
            'command'      => 'modules_v4/linkenhancer/cli/build-link-index.php',
            // 'command_type' => 'module',  // optional - derived from the command value
            'description'  => 'Rebuild the link index incrementally.',
            'params' => [
                ['name' => '--limit', 'optional' => true, 'default' => '5000', 'description' => 'max records per run'],
                ['name' => '--since', 'optional' => true, 'description' => 'only changes since this timestamp'],
            ],
        ],
    ],
];
```

**Command spec fields**

| Key | Required | Meaning |
| :--- | :--- | :--- |
| `command` | yes | a `modules_v4/…/cli/*.php` path or an allowlisted core command, max 255 |
| `command_type` | no | `module` or `core` (derived from `command` when omitted) |
| `description` | no | shown in the command catalog (translation key, max 255) |
| `params` | no | list of parameter descriptors (max 8) |

Each parameter descriptor has `name` (the option token, e.g. `--limit`), `optional`
(bool, default `true` — set `false` for a required option), `default` (an optional
value) and `description` (optional). Equivalent marker method:
`getModuleCommands(): array` (same command-spec shape).

> **Curation rule:** a module that announces commands **curates its own command list** —
> the glob-based discovery no longer lists *its* scripts, so internal scripts
> (`tick.php`, `watch.php`, `wrap.php`, `smoke-job.php`) are not offered as job
> commands. A module that announces nothing falls back to glob discovery of its
> `cli/` scripts (backward compatible).

### Job spec fields

| Key | Required | Meaning |
| :--- | :--- | :--- |
| `name` | yes | slug `a-z 0-9 _ -`, max 64; stored as `<module>:<name>` |
| `title` | no | human name (defaults to `name`) |
| `triggers` | yes | list of trigger entries: `['type' => 'time', 'cron' => '…']` and/or `['type' => 'event', 'event' => '…']`, at least one (mixing both is allowed - the job fires on any of them). The legacy single-trigger keys `trigger_type` + `cron` / `event_name` are still accepted and normalized to one trigger. |
| `command_type` | yes | `module` (`modules_v4/.../cli/*.php`) or `core` (allowlisted) |
| `command` | yes | the command - validated exactly like a hand-made job |
| `args` | no | plain option/value tokens |
| `enabled` | no | default `false` - discovered jobs are **created disabled** |
| `timeout_sec` | no | default `300`, range 30 - 3600 |

A malformed or misbehaving manifest is skipped silently - it can never break the
tick. Once a discovered job is inserted it is **admin-owned**: later ticks never
overwrite it, so edits to triggers, arguments or the enabled flag are safe. (A spec
*added* to the manifest appears on the next tick; a *removed* spec leaves the
already-created job in place.)

**Exception - the module's own manifest.** cronjob also ships a
`modules_v4/cronjob/cron-jobs.php` that offers its own `cronjob:pseudo-events`
job. Unlike external jobs, this one is **force-synced to the manifest on every
tick**: if a module update changes the spec (triggers, timeout, command, …), the
stored job is re-synced to the new values within one tick. The admin's `enabled`
state, the notify flag, the slug and the run history are preserved, and the next
run is only rescheduled when the trigger set itself changed.

In the admin table, every job that is **currently offered by a module** carries a
"from module …" badge. Its **Reset** button restores the defaults currently in the
manifest (title, triggers, command, args, timeout, enabled state); the slug,
the notify setting and the run history are kept. If the module is removed or no
longer offers the spec, the badge and the reset button disappear.

## Using the W1 wrapper (cronjob's bootstrap for other modules' scripts)

A job script that needs webtrees + the database must bootstrap them itself. A module
that already depends on cronjob can delegate that to a shared wrapper instead of
copying the bootstrap:

1. **Payload** `modules_v4/<module>/cli/<name>.logic.php` - the real logic; it reads
   `$argv` like any CLI script. It keeps a 1-line SAPI guard (it is a `.php` file
   inside `cli/`, hence URL-reachable).
2. **Stub** `modules_v4/<module>/cli/<name>.php` - what the job row points to:

   ```php
   <?php
   declare(strict_types=1);
   $wrapper = __DIR__ . '/../../cronjob/cli/wrap.php';
   if (is_file($wrapper)) {
       require $wrapper;
   } else {
       fwrite(STDERR, "cronjob module required for this job\n");
       exit(1);
   }
   ```

`wrap.php` bootstraps webtrees + DB and then includes the payload - the *sibling* of
the stub with the extension swapped from `.php` to `.logic.php`. That payload path is
**derived from the entry script, never taken from an argument**, so there is no
attacker-controlled include path (the confinement is enforced in
`CliBootstrap::resolvePayloadPath()`). The job row stays `<name>.php`, unchanged; the
job's working directory and arguments are unchanged too.

- The wrapper is **never itself a job command** - only the stub is.
- **Requires the cronjob module to be installed.** If a script must also run as a
  standalone tool (no cronjob), keep the module's own `CliBootstrap` copy instead.

## Event-driven jobs (webhooks & event queue)

Besides time-based schedules, a job can be triggered by an **event**. webtrees has no
event system, so events are plain DB rows (a small `cj_event` queue) that the tick
drains once a minute. An event-triggered job runs for a queued event whose name
matches its **Event name** - **coalesced (1.1.0):** per tick at most the *newest*
event of each name is drained (one run per name per tick), and the name's older
pending duplicates are dropped - a burst of identical events never queues a burst of
job runs (job scripts are idempotent/incremental by convention).

**Naming scheme (analogous to job names).** Event names are slugs
(`a-z 0-9 _ -`, max 64 chars total), optionally namespaced `<domain>:<slug>`:

| Form | Meaning |
| :--- | :--- |
| plain slug (e.g. `index-dirty`) | your own events - webhook or direct `EventQueue::push()` |
| `cronjob:<slug>` | built-in pseudo-events (state pollers) |
| `_route:<slug>` | route-triggered events (see below) |
| `<module>:<slug>` | events announced by a module (see "Announcing events") |

Queuing an event has four sources:

1. **Webhook (external systems).** GET the endpoint shown in the admin page
   (section "Event webhook"):
   `GET /module/_cronjob_/Event?event=<name>&data=<json>` with the token in the
    `X-Cronjob-Token` header (**header only** - since 1.1.0 the `?token=<token>`
    query fallback is rejected, because a token in the URL ends up in access logs).
    `data` is an optional JSON object, stored with the event. The endpoint requires
    the token, is rate-limited (20/min per site), and only accepts events while the
    module is enabled. It is a **GET**, not a POST: webtrees rejects POSTs without a
    session CSRF token, which an external caller cannot send.
2. **Direct call (your own modules).** From PHP code in another module:
   `\Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue::push('index-dirty', ['tree' => 'X']);`
   - this requires the cronjob module to be installed; guard it with
     `class_exists('Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue')`.
3. **Pseudo-events (built-in pollers).** webtrees core actions (a GEDCOM import,
   new media, a new user) cannot be hooked without a core change, so they are
   *observed by polling*. Enable the offered `cronjob:pseudo-events` job and the
   module checks the state below at most every 5 minutes, queueing an event on each
   transition. See "Pseudo-events (state polling)".
 4. **Route events (built-in, request-triggered).** A curated set of mutating
    editor routes under `/tree/{tree}/` (record-specific routes with `{xref}` in
    the path, plus a small tree-level allowlist) queues its event immediately,
    right inside the request that made the change. See
    "Route events (request-triggered)".

The tick matches each pending event against the enabled jobs that carry an event
trigger for that name (a job can have several event triggers, or none at all), runs
them, then marks the event handled (consumed once - even on failure, so a failing job
does not re-run the same event forever). The drain is bounded: it takes the newest
event per name (coalescing, see above) and stops after a 60 s wall-clock budget, so a
saturated queue cannot starve the rest of the tick - the remaining events simply
continue with the next tick. Every consuming run is additionally linked to
the event in the `cj_event_run` cross table (an event can trigger several jobs), and
the run history shows which event triggered a run. Handled events are purged after
7 days (their `cj_event_run` links are removed by the FK cascade); events with no
matching job are discarded so the queue does not grow.

**Event payload in the child process.** Event runs hand the event to the job script
via the process environment (the argv is reserved for the job's static, strictly
validated arguments):

| Variable | Set when | Value |
| :--- | :--- | :--- |
| `CRONJOB_EVENT` | every event run | the event name (doubles as the "this is an event run" marker) |
| `CRONJOB_EVENT_PAYLOAD` | event run **with** a non-empty payload | the event payload as JSON (route parameters, pseudo-event state, webhook `data`) |

```php
$event   = getenv('CRONJOB_EVENT');                       // false on schedule / manual runs
$payload = json_decode((string) getenv('CRONJOB_EVENT_PAYLOAD'), true) ?: [];
$xref    = $payload['xref'] ?? '';                        // e.g. from a _route:* event
```

- **Variable absent = no payload.** The payload is truncated to 4000 bytes when the
  event is queued; a JSON cut mid-way no longer decodes, in which case
  `CRONJOB_EVENT_PAYLOAD` is *not set at all* (never empty, never broken JSON).
- **Treat the payload as untrusted input** - above all for webhook `data`, whose
  sender is external. Validate values (xrefs, names, …) before using them.
- The payload is independent of the command type: module CLI scripts *and* core
  commands (which simply ignore the variables) both work on event runs.
- The payload is also visible in the run history (event detail), independent of the
  child process.

## Pseudo-events (state polling)

webtrees has no event bus, so core actions you cannot hook into (a standard GEDCOM
import, media being added, a user registering) are detected by **polling state** and
firing an event when the state transitions. It is **off by default**: nothing runs
until you enable the offered `cronjob:pseudo-events` job.

**Built-in detectors** (each emits a matching event for an event-triggered job):

| Event | Fires when | State polled |
| :--- | :--- | :--- |
| `cronjob:gedcom-changed` | a GEDCOM file in `data/` changed (mtime or size) — i.e. a standard import/export re-wrote it | per-tree file mtime + size |
| `cronjob:media-added` | the `media_file` row count increased | `COUNT(media_file)` |
| `cronjob:user-registered` | `MAX(user_id)` grew (a new user signed up) | `MAX(user.user_id)` |

**How it runs**

- **One trigger, one cooldown.** Detection runs only from the offered
  `cronjob:pseudo-events` job (`*/5 * * * *` by default), in the tick's child process
  — isolated from any web request and bounded by the job's timeout, so a heavy
  detector can never block or slow down a page load. A 5-minute cooldown (a `data/`
  mtime marker) plus a lock keep repeated runs cheap and idempotent; the cooldown
  also caps a more frequent job cron.
- **Enabled by the job.** Enabling the `cronjob:pseudo-events` job is what turns
  detection on (there is no separate switch — the tick only runs enabled jobs).
  Detection additionally no-ops while no enabled event-triggered job is listening,
  so it never polls for nothing.
- **Baseline on first run.** The first detection only records the current state and
  fires nothing, so enabling the job does not retroactively fire on existing data.
- **Offline-safe & isolated.** Skipped while `data/offline.txt` exists; a broken
  detector never breaks the others; detection is serialized (flock) and state is
  persisted atomically to `data/cronjob-pseudo-events.json`.

Detected events go into the same `cj_event` queue as webhooks and are consumed by the
tick's normal event drain. To act on one, create an event-triggered job whose
**Event name** matches (e.g. re-run the linkenhancer index on
`cronjob:gedcom-changed`).

> The GEDCOM detector watches the file in `data/`, so it reflects imports/exports that
> rewrite that file. It is a polling heuristic (~5 min latency), not a true hook — a
> hook would require a core change (out of scope).

## Route events (request-triggered)

A complementary, *immediate* detection mechanism: instead of polling state on a
schedule, the module observes the HTTP request itself. When a request to a **mapped
mutating route** has been handled successfully (2xx/3xx), it queues the matching
event into the same `cj_event` queue — a listening event job then reacts at the next
tick (~60 s), with no polling latency.

**Which routes are mapped** — two rules, both derived from the live webtrees route
table. Common prerequisites: path starts with `/tree/{tree}/`, HTTP **POST** only
(the GET page routes of the same actions are not mapped), handler in webtrees'
`RequestHandlers` namespace.

1. **Record routes** (rule-based — core updates are picked up automatically): the
   path contains `{xref}` (the target record) *and* the handler class name starts
   with `Edit`, `Delete`, `Add`, `Create`, `Link`, `Paste`, `Reorder` or `Pending`.
   The event name is the third path segment, namespaced `_route:<segment>`; when
   several mapped routes share a segment (`delete`, `edit-raw`, `accept`, `reject`),
   the following path parameters are appended, hyphen-separated.
2. **Tree-level routes** (small curated allowlist): the significant tree-wide
   mutations that carry no `{xref}` in the URL (import, merge, renumber, …). Each
   allowlist entry has an explicit event name. New tree-wide mutations in a core
   update need a module update (the record-route rule stays fully automatic).

| Event | Route (under `/tree/{tree}/`) | Payload |
| :--- | :--- | :--- |
| `_route:edit-note-object` | POST `edit-note-object/{xref}` | `tree`, `xref` |
| `_route:update-record` | POST `update-record/{xref}` | `tree`, `xref` |
| `_route:update-fact` | POST `update-fact/{xref}{/fact_id}` | `tree`, `xref` (, `fact_id`) |
| `_route:delete-xref` / `_route:delete-xref-fact_id` | POST `delete/{xref}{/fact_id}` | `tree`, `xref` (, `fact_id`) |
| `_route:edit-raw-xref` / `_route:edit-raw-xref-fact_id` | POST `edit-raw/{xref}{/fact_id}` | `tree`, `xref` (, `fact_id`) |
| `_route:add-child-to-family`, `_route:add-spouse-to-family`, `_route:add-media-file` | POST `add-…/{xref}` | `tree`, `xref` |
| `_route:add-child-to-individual`, `_route:add-parent-to-individual`, `_route:add-spouse-to-individual` | POST `add-…/{xref}` | `tree`, `xref` |
| `_route:paste-fact` | POST `paste-fact/{xref}` | `tree`, `xref` |
| `_route:link-media-to-record` | POST `link-media-to-record/{xref}` | `tree`, `xref` — the **media** object; the target record is a form field, not in the URL |
| `_route:link-child-to-family`, `_route:link-spouse-to-individual` | POST `link-…/{xref}` | `tree`, `xref` |
| `_route:reorder-children`, `_route:reorder-spouses`, `_route:reorder-media`, `_route:reorder-media-files`, `_route:reorder-names` | POST `reorder-…/{xref}` | `tree`, `xref` |
| `_route:accept-xref` / `_route:accept-xref-change` | POST `accept/{xref}{/change}` | `tree`, `xref` (, `change`) |
| `_route:reject-xref` / `_route:reject-xref-change` | POST `reject/{xref}{/change}` | `tree`, `xref` (, `change`) |
| `_route:import` | POST `import` | `tree` |
| `_route:load` | POST `load` | `tree` — the chunked import continuation fires **once per chunk** |
| `_route:merge-step1` / `_route:merge-step2` | POST `merge-step1` / `merge-step2` | `tree` |
| `_route:search-replace` | POST `search-replace` | `tree` |
| `_route:renumber` | POST `renumber` | `tree` |
| `_route:data-fix-update` / `_route:data-fix-update-all` | POST `data-fix/{data_fix}/update[-all]` | `tree`, `data_fix` |
| `_route:accept` / `_route:reject` | POST `accept` / `reject` (bulk) | `tree` |
| `_route:change-family-members` | POST `change-family-members` | `tree` — the family xref is a form field |

In total 38 events (27 record routes + 11 tree-level routes). The payload always
contains `tree` (id) + `tree_name` plus all matched route parameters.

> **Breaking change (this version).** Record routes *without* `{xref}` in the URL —
> all `_route:create-*` events plus `_route:add-unlinked-individual` — are no longer
> mapped: they carry no target record. Jobs listening to those events will never
> fire again; the admin table flags such triggers with an **unknown route event**
> badge (a generic check over all jobs' `_route:*` triggers against the live map),
> so re-pointing them is visible instead of silent.
>
> `_route:accept*` / `_route:reject*` fire when a *pending change* is accepted or
> rejected — accepting a pending deletion is when the record is actually removed.

**How it works**

- **Gated twice, off by default.** An event fires only while the admin toggle
  "Route events" (Events section) is on *and* at least one enabled event-triggered
  job listens for that event name. A request that is not mapped costs nothing (one
  in-memory lookup); only a successful request on a mapped route does two tiny
  indexed DB reads.
- **Transactional.** The `cj_event` insert runs in the *same DB transaction* as the
  mutation (the module middleware sits inside webtrees' request transaction), so
  the queued event commits or rolls back together with the change — no drift, no
  double fire on a failed save.
- **Never blocks.** Detection runs *after* the handler, is exception-wrapped, and
  can never alter the response.
- **Robust to core upgrades.** The map is rebuilt from the route table (keyed by
  handler class *strings*, no `::class`), so a renamed/removed handler simply stops
  matching instead of fatalling. If the route table is not available (e.g. CLI), a
  static fallback map with the note route keeps the pilot working. The record-route
  rule is fully automatic; only the small tree-level allowlist is curated (see
  above), and jobs pointing at a route event that no longer exists are flagged in
  the admin table (**unknown route event** badge).

To act on one, create an event-triggered job whose **Event name** matches (e.g.
`_route:update-record`) and enable the "Route events" toggle. Note the overlap with
the `cronjob:gedcom-changed` poller: an editor save rewrites the GEDCOM file, so a
`cronjob:gedcom-changed` event follows at the next poll as well — pick the mechanism
whose latency/precision fits the job (route events are near-instant and precise per
object; pollers are coarser but also catch changes made outside the web UI).

## Event catalog

The admin page (Events section) and the job form's event-name suggestions are fed by
an **event inventory** (`cj_event_catalog`, synced by the tick like the job registry).
It merges, in this priority order:

1. events **announced by modules** (manifest `events` section / `getModuleEvents()`),
   namespaced `<module>:<name>`
2. **built-in pseudo-events** (`cronjob:*`, from the detectors)
3. **route events** (`_route:*`, derived from the live route table)
4. event names currently **listened for** by an enabled event job (source
   `listening`) — this covers webhook-only names

Each entry carries its source, a description (stored as a translation key, translated
at render time only) and the known payload parameter names. For **route events** the
payload is **derived from the route path**: the `{param}` placeholders in the order
they appear, with `{tree}` expanding to `tree` + `tree_name` (mirroring what the live
event payload carries). The full list is also what the "Event name" datalist in the job
form offers. The catalog is rendered as a client-side **DataTables** (sort/filter/
paging) in the Events section.

## Command catalog

The job form's command suggestions and the admin page (Events section) are fed by a
**command inventory** (`cj_command_catalog`, synced by the tick like the others). It
merges, in this priority order (first source wins per command):

1. the **allowlisted core commands** (`tree-export`, `tree-list`, `user-list`,
   `site-setting`), with their known parameters
2. commands **announced by modules** (manifest `commands` section /
   `getModuleCommands()`) — with a description and structured parameters
3. **module CLI scripts** found by glob (`modules_v4/<module>/cli/*.php`), *except*
   for a module that announced commands (that module curates its own list, so its
   internal scripts are not offered)

Each entry carries its type (`module` / `core`), its source, a description (translation
key, translated at render time) and the available parameters (structured: name,
optional, default, description). In the job form, entering a command that matches an
announced command shows its parameters as a hint under the field.

## Translation (i18n)

All user-facing strings are extracted with `xgettext` - no manual PO entries:

- View strings use `I18N::translate()` as usual. Generic strings that already exist
  in the core `webtrees.pot` are marked with a `/* I18N: webtrees.pot */` comment, so
  the module-local POT excludes them (dedupe).
- **Manifest literals** (`cron-jobs.php` is pure data, loaded in tick/CLI context -
  `I18N::translate()` must not run there) are wrapped in `I18nMark::translate()`, an
  *identity* marker whose last qualified-name component matches xgettext's
  `--keyword=translate`. Nothing is translated at manifest load time.
- **Pipeline:** `util/update-po-files.sh` generates `resources/lang/messages.pot`
  (from `messages.all.pot`, core-pot entries filtered out) - PO files are maintained
  via Weblate and land in `resources/lang/<language>.po|.php`. The module's
  `CronjobModule::customTranslations()` feeds them into webtrees' `I18N::init()`, so
  the views' `I18N::translate()` calls find the translations **at render time**.
- **Job titles** are admin-editable DB values, translated at render time through
  `CronbookUtils::translateJobTitle()` (job table, breadcrumbs, history header): the
  default title from a manifest appears in the UI language, renamed jobs stay as-is
  (gettext miss). Titles containing `%` are deliberately **not** translated -
  `I18N::translate()` applies `sprintf()` to its result, where a bare `%` would be an
  invalid conversion specification. The job form's title **input** always shows the
  raw stored value (otherwise the translation would be written back to the DB on save).
- **Notifications** (tick/CLI context) are not translated.

## Failure notification

A job can be marked **Notify on failure**. When such a job fails (non-zero exit or
timeout), the site's **administrator** accounts are alerted through webtrees' own
`MessageService::deliverMessage()` - which delivers via the **internal message system**
and/or **e-mail** according to *each recipient's own* contact-method preference. No
addresses are stored in the module and no channel is hardcoded: the recipients are the
admin user accounts, and how they are reached follows the site's messaging settings.

The notification carries the job name, status, exit code, duration and a short tail of
the captured output. It runs in the tick (no web request), so it carries no link
beyond the text. A notification problem never breaks the tick.

## Job outlook (candidates for the first jobs)

| Job | Command | Trigger |
| :--- | :--- | :--- |
| Linkenhancer link-index update | `modules_v4/linkenhancer/cli/build-link-index.php --limit=5000` | time `*/30 * * * *` |
| GEDCOM backup (tree export) | `tree-export <tree_name>` — writes `data/<tree_name>.ged`, **full personal data**; plan retention/cleanup of `data/*.ged` | time `0 3 * * 0` |
| Config backup | `site-setting --list` — output lands in the run history (admin-only); for a file backup use a small module CLI script | time `0 3 * * 0` |
| Smoke test | `modules_v4/cronjob/cli/smoke-job.php` | time `0 4 * * *` |
| Re-index on change (event) | `modules_v4/linkenhancer/cli/build-link-index.php` | event `linkenhancer:index-dirty` (announced by the linkenhancer manifest; queued by its code or a webhook) |
| Re-index on GEDCOM change (event) | `modules_v4/linkenhancer/cli/build-link-index.php` | event `cronjob:gedcom-changed` |
| Re-index on any record edit (event) | `modules_v4/linkenhancer/cli/build-link-index.php` | event `_route:update-record` (route events must be enabled) |
| Pseudo-events poll (offered job) | `modules_v4/cronjob/cli/pseudo-events.php` | time `*/5 * * * *` (fires `cronjob:gedcom-changed` / `cronjob:media-added` / `cronjob:user-registered`) |

## Roadmap (phase 2)

Implemented so far: **job self-registration** (manifest / marker method, incl.
**event announcements**), the **W1 wrapper**, **event-driven jobs** (webhook +
`cj_event` queue + `EventQueue::push()`), **pseudo-events** (state pollers:
`cronjob:gedcom-changed` / `cronjob:media-added` / `cronjob:user-registered`, driven
by the offered `cronjob:pseudo-events` job), **forced re-sync of the module's own
manifest job** on update, **failure notification** to the administrator accounts, a
**human-readable schedule** shown next to each cron expression in the admin table
(translatable via `I18N`; the exact cron string is always shown too), the
**provenance badge + reset to module defaults** for offered jobs, **duplicate a
job** via the create form, the **client-side DataTable** (filter/sort/paging)
for the job table, **Notify/Timeout table columns**, editable **module-offered
job names**, the **event naming scheme** (`<domain>:<slug>`, `_route:*` /
`cronjob:*` / `<module>:*`), **route events** (record routes: POST + `{xref}` in
the path + mutating handler prefix `Edit/Delete/Add/Create/Link/Paste/Reorder/
Pending` under `/tree/{tree}/`, plus a curated tree-level allowlist for import,
merge, renumber, search-replace, data fixes and bulk accept/reject — 38 events,
opt-in toggle, orphaned-event warning for dead triggers), the
**event catalog** (module announcements + built-ins + route events + listened
names, in `cj_event_catalog`), the **`cj_event_run` cross table** with triggering
events shown in the run history, **PRG form handling** (a failed save redirects
to the form page with the entered values kept), the **route-event payload derived
from the route path** in the event catalog, **DataTables** for the event and command
catalogs in the admin page, and the **command catalog** (`cj_command_catalog`: core
allowlist + module command announcements with structured parameters + module scripts,
with a per-module curation rule so internal scripts are not offered, and a dynamic
parameter hint in the job form), **multi-trigger jobs** (triggers moved to the
`cj_job_trigger` cross table: a job can combine any number of cron schedules and
event triggers - it fires on any of them; the job's `next_run_at` is the minimum over
its time triggers, the run history records which cron/event fired, and "Run now" on
a disabled job runs once at (re-)enable), the **refined route-event rules** (record
routes now require `{xref}` in the path and use the extended mutating prefixes
`Link/Paste/Reorder/Pending`; a curated tree-level allowlist adds import, merge,
renumber, search-replace, data fixes and bulk accept/reject - 38 events; orphaned
`_route:*` triggers of jobs are flagged with an "unknown route event" badge), and
**event payload in the child process** (event runs set `CRONJOB_EVENT` and, when the
event carried one, `CRONJOB_EVENT_PAYLOAD` (JSON) in the job script's environment),
plus the **security hardening of 1.1.0** (event-queue coalescing + 60 s event-drain
budget, webhook token header-only, output escaping in the job views, safe
`confirm()` dialogs, and the corrected smoke-test catalog entry).
See the sections above.

Still open:

- **Linkenhancer side of self-registration** — the linkenhancer manifest (L1) and
  the W1 migration of `build-link-index.php` (L2) live in that module and are done
  in a separate session (module boundaries).

## Tests

```bash
php modules_v4/cronjob/tests/test-args-validator.php  # command whitelist + arg validation (standalone)
php modules_v4/cronjob/tests/test-cron-wrapper.php    # cron semantics (skips cleanly without the bundled vendor)
php modules_v4/cronjob/tests/test-cron-humanize.php   # human-readable cron descriptions (standalone)
php modules_v4/cronjob/tests/test-watch-service.php   # watch daemon logic: opt-in marker, liveness lock, cooldown (standalone)
php modules_v4/cronjob/tests/test-job-spec.php        # self-registration: job+event+command spec validators, manifest loading (jobs/events/commands), event naming, command-catalog merge, W1 payload confinement, multi-trigger (normalizeTriggers/triggersKey/nextRunMin/dueTriggerDetails, specDiff) (standalone)
php modules_v4/cronjob/tests/test-pseudo-events.php   # pseudo-events: detector transition logic, specDiff, state/cooldown (standalone)
php modules_v4/cronjob/tests/test-route-events.php   # route events: map builder (record-route rule, tree-level allowlist, filters, collision suffixes, fallback), orphaned-event detection, success gate, payload builder, payload-key derivation from path (standalone)
php modules_v4/cronjob/tests/test-event-queue.php    # event-queue coalescing: newest per event name, name cap, ordering (standalone)
php modules_v4/cronjob/tests/test-i18n-mark.php      # i18n: I18nMark identity, manifest literals/shape unchanged, translateJobTitle % guard (standalone)
```

## License

GPL-3.0-or-later, like webtrees itself.
