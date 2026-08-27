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
| **Trigger** | **Time-based** (a cron schedule) or **Event** (runs when a named event is queued). See "Event-driven jobs". |
| **Cron expression** | (time-based only) standard 5-field cron (`*/30 * * * *`), or a macro (`@daily`, `@weekly`, ...). **Times are UTC** (the webtrees server time basis - the same basis the core uses for all timestamps). The form previews the next 5 runs. |
| **Event name** | (event only) the event this job reacts to, e.g. `linkenhancer:index-dirty`. Suggestions come from the **event catalog** (module announcements, built-in pseudo-events `cronjob:…`, route events `_route:…` and listened names); any own name (webhook / direct module push) works too. Slug or `<domain>:slug`, max 64 chars. |
| **Command** | a `modules_v4/<module>/cli/<script>.php` path (discovered scripts are offered as suggestions) or an allowlisted core command: `tree-export`, `tree-list`, `user-list`, `site-setting` |
| **Arguments** | plain options/values only (e.g. `--limit=5000`), max 10 tokens |
| **Timeout** | 30 - 3600 s |
| **Notify on failure** | opt-in: alert the administrator accounts when this job fails |

The **Run now** button queues the job for the next tick (≤ 60 s). The **History**
page shows status, exit code, duration and the captured output (last 64 KB) of the
last 25 runs per job (plus a 30-day global retention window).

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
        'cron'         => '*/30 * * * *',
        'command_type' => 'module',
        'command'      => 'modules_v4/linkenhancer/cli/build-link-index.php',
        'args'         => '--limit=5000',
        // 'enabled'      => false,  // default: created disabled (opt-in)
        // 'timeout_sec'  => 300,    // default
    ],
];
```

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
            'name'         => 'link-index',
            'cron'         => '*/30 * * * *',
            'command_type' => 'module',
            'command'      => 'modules_v4/linkenhancer/cli/build-link-index.php',
        ],
        [
            'name'         => 'reindex-on-dirty',
            'trigger_type' => 'event',
            'event_name'   => 'linkenhancer:index-dirty', // listen to your own event
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
- An event job's `event_name` that carries no colon is auto-namespaced
  `<module>:<event>`; a name that already has a colon (e.g.
  `cronjob:gedcom-changed`) is kept as-is.
- Equivalent marker method: `getModuleEvents(): array` (same event-spec shape).

**Event spec fields**

| Key | Required | Meaning |
| :--- | :--- | :--- |
| `name` | yes | slug `a-z 0-9 _ -` (stored as `<module>:<name>`) |
| `description` | no | shown in the event catalog (translation key, max 255) |
| `payload` | no | list of payload parameter names (max 8), shown in the catalog |

### Job spec fields

| Key | Required | Meaning |
| :--- | :--- | :--- |
| `name` | yes | slug `a-z 0-9 _ -`, max 64; stored as `<module>:<name>` |
| `title` | no | human name (defaults to `name`) |
| `cron` | yes | 5-field cron expression |
| `command_type` | yes | `module` (`modules_v4/.../cli/*.php`) or `core` (allowlisted) |
| `command` | yes | the command - validated exactly like a hand-made job |
| `args` | no | plain option/value tokens |
| `enabled` | no | default `false` - discovered jobs are **created disabled** |
| `timeout_sec` | no | default `300`, range 30 - 3600 |

A malformed or misbehaving manifest is skipped silently - it can never break the
tick. Once a discovered job is inserted it is **admin-owned**: later ticks never
overwrite it, so edits to cron, arguments or the enabled flag are safe. (A spec
*added* to the manifest appears on the next tick; a *removed* spec leaves the
already-created job in place.)

**Exception - the module's own manifest.** cronjob also ships a
`modules_v4/cronjob/cron-jobs.php` that offers its own `cronjob:pseudo-events`
job. Unlike external jobs, this one is **force-synced to the manifest on every
tick**: if a module update changes the spec (cron, timeout, command, …), the
stored job is re-synced to the new values within one tick. The admin's `enabled`
state, the notify flag, the slug and the run history are preserved, and the next
run is only rescheduled when the cron expression itself changed.

In the admin table, every job that is **currently offered by a module** carries a
"from module …" badge. Its **Reset** button restores the defaults currently in the
manifest (title, trigger, cron, command, args, timeout, enabled state); the slug,
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
drains once a minute. An event-triggered job runs **once for each queued event** whose
name matches its **Event name**.

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
   `X-Cronjob-Token` header (or, less securely, as `?token=<token>`). `data` is an
   optional JSON object, stored with the event. The endpoint requires the token, is
   rate-limited (30/min per site), and only accepts events while the module is enabled.
   It is a **GET**, not a POST: webtrees rejects POSTs without a session CSRF token,
   which an external caller cannot send.
2. **Direct call (your own modules).** From PHP code in another module:
   `\Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue::push('index-dirty', ['tree' => 'X']);`
   - this requires the cronjob module to be installed; guard it with
     `class_exists('Schwendinger\Webtrees\Module\Cronjob\Services\EventQueue')`.
3. **Pseudo-events (built-in pollers).** webtrees core actions (a GEDCOM import,
   new media, a new user) cannot be hooked without a core change, so they are
   *observed by polling*. Enable the offered `cronjob:pseudo-events` job and the
   module checks the state below at most every 5 minutes, queueing an event on each
   transition. See "Pseudo-events (state polling)".
4. **Route events (built-in, request-triggered).** A rule-based set of mutating
   editor routes (every POST `edit…/delete…/add…/create…` route under
   `/tree/{tree}/`) queues its event immediately, right inside the request that made
   the change. See "Route events (request-triggered)".

The tick matches each pending event against enabled `trigger_type='event'` jobs, runs
them, then marks the event handled (consumed once - even on failure, so a failing job
does not re-run the same event forever). Every consuming run is additionally linked to
the event in the `cj_event_run` cross table (an event can trigger several jobs), and
the run history shows which event triggered a run. Handled events are purged after
7 days (their `cj_event_run` links are removed by the FK cascade); events with no
matching job are discarded so the queue does not grow.

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

**Which routes are mapped** (rule-based, derived from the live webtrees route table —
no manual list, so core updates are picked up automatically):

- path starts with `/tree/{tree}/`
- HTTP **POST** only (the GET page routes of the same actions are not mapped)
- handler in webtrees' `RequestHandlers` namespace whose class name starts with
  `Edit`, `Delete`, `Add` or `Create` (this excludes non-mutating POSTs like
  `SelectNewFact` / `CopyFact` / `PasteFact` and `Link*` / `Reorder*` / `Change*`)

**Event names.** The third path segment, namespaced `_route:<segment>`. When two
mapped routes share a segment (currently `delete` and `edit-raw`), the following path
parameters are appended, hyphen-separated:

| Event | Route | Payload |
| :--- | :--- | :--- |
| `_route:edit-note-object` | POST `/tree/{tree}/edit-note-object/{xref}` | `tree`, `xref` |
| `_route:update-record` | POST `/tree/{tree}/update-record/{xref}` | `tree`, `xref` |
| `_route:update-fact` | POST `/tree/{tree}/update-fact/{xref}{/fact_id}` | `tree`, `xref` (, `fact_id`) |
| `_route:delete-xref` | POST `/tree/{tree}/delete/{xref}` | `tree`, `xref` |
| `_route:delete-xref-fact_id` | POST `/tree/{tree}/delete/{xref}/{fact_id}` | `tree`, `xref`, `fact_id` |
| `_route:edit-raw-xref` / `_route:edit-raw-xref-fact_id` | POST `/tree/{tree}/edit-raw/…` | same |
| … 17 more | all remaining POST `edit…/delete…/add…/create…` routes | the route's parameters |

In total 23 events: every family/individual/spouse/child addition (`_route:add-*`),
record creation (`_route:create-*`, incl. notes, repositories, sources, media,
locations, submitters, submissions), the record/fact/media/note/raw edits above and
the two deletes. The payload always contains `tree` (id) + `tree_name` plus all
matched route parameters (`xref`, `fact_id`, …).

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
  static fallback map with the note route keeps the pilot working.

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
at render time only) and the known payload parameter names. The full list is also
what the "Event name" datalist in the job form offers.

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
`cronjob:*` / `<module>:*`), **rule-based route events** (all POST
`edit…/delete…/add…/create…` routes under `/tree/{tree}/`, opt-in toggle), the
**event catalog** (module announcements + built-ins + route events + listened
names, in `cj_event_catalog`), the **`cj_event_run` cross table** with triggering
events shown in the run history, and **PRG form handling** (a failed save redirects
to the form page with the entered values kept). See the sections above.

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
php modules_v4/cronjob/tests/test-job-spec.php        # self-registration: job+event spec validators, manifest loading, event naming, W1 payload confinement (standalone)
php modules_v4/cronjob/tests/test-pseudo-events.php   # pseudo-events: detector transition logic, specDiff, state/cooldown (standalone)
php modules_v4/cronjob/tests/test-route-events.php   # route events: rule-based map builder (filters, collision suffixes, fallback), success gate, payload builder (standalone)
```

## License

GPL-3.0-or-later, like webtrees itself.
