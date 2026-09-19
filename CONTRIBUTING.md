# CONTRIBUTING

## Translation (i18n)

All user-facing strings are extracted with `xgettext` - no manual PO entries:

- View strings use `I18N::translate()` as usual. Generic strings that are already
  provided by webtrees **core** (e.g. `Details`, `Delete`, the day names) are wrapped
  in `MoreI18N::xlate()` instead: functionally identical at runtime, but the
  different call name is invisible to xgettext - so they are never extracted into
  the module POT and their translations come from the core POT. (This replaces
  linkenhancer's `/* I18N: webtrees.pot */` comment + shell-filter approach.)
- **Workflow after a core update:** compare `resources/lang/messages.all.pot`
  (the full extraction) with the current core POT; every msgid that core now covers
  gets masked with `MoreI18N::xlate()` in the code.
- **Manifest literals** (`cron-jobs.php` is pure data, loaded in tick/CLI context -
  `I18N::translate()` must not run there) are wrapped in `MoreI18N::translate()`, an
  *identity* marker whose last qualified-name component matches xgettext's
  `--keyword=translate`. Nothing is translated at manifest load time.
- **Pipeline:** `util/update-po-files.sh` runs xgettext over the module
  (`util/`/`vendor/`/`tests/` excluded) into `resources/lang/messages.all.pot` and
  copies it to `resources/lang/messages.pot` (no filter step - the core dedupe lives
  in the code via `MoreI18N::xlate`). PO files are maintained via Weblate and land
  in `resources/lang/<language>.po`; `util/compile-po.php` then compiles them to
  `*.php` (same output as the core `compile-po-files` command, standalone without
  the webtrees bootstrap - `create-archive.sh` calls it). The module's
  `CronjobModule::customTranslations()` feeds the compiled `*.php` (preferred) or
  `*.po` files into webtrees' `I18N::init()`, so the views' translation calls find
  them **at render time**.
- **Job titles** are admin-editable DB values, translated at render time through
  `CronjobUtils::translateJobTitle()` (job table, breadcrumbs, history header): the
  default title from a manifest appears in the UI language, renamed jobs stay as-is
  (gettext miss). Titles containing `%` are deliberately **not** translated -
  `I18N::translate()` applies `sprintf()` to its result, where a bare `%` would be an
  invalid conversion specification. The job form's title **input** always shows the
  raw stored value (otherwise the translation would be written back to the DB on save).
- **Notifications** (tick/CLI context) are not translated.

## Tests

```bash
php modules_v4/cronjob/tests/test-args-validator.php  # command whitelist + arg validation (standalone)
php modules_v4/cronjob/tests/test-cron-wrapper.php    # cron semantics (skips cleanly without the bundled vendor)
php modules_v4/cronjob/tests/test-cron-humanize.php   # human-readable cron descriptions (standalone)
php modules_v4/cronjob/tests/test-watch-service.php   # watch daemon logic: opt-in marker, liveness lock, cooldown (standalone)
php modules_v4/cronjob/tests/test-job-spec.php        # self-registration: job+event+command spec validators, manifest loading (jobs/events/commands), event naming, command-catalog merge, W1 payload confinement, multi-trigger (normalizeTriggers/triggersKey/nextRunMin/dueTriggerDetails, specDiff) (standalone)
php modules_v4/cronjob/tests/test-pseudo-events.php   # pseudo-events: log-row detector (registry, catalog info, truncation, checkpoint/backpressure/reset, payload shape), orphan/prune helpers, specDiff, state/cooldown (standalone)
php modules_v4/cronjob/tests/test-route-events.php   # route events: map builder (record-route rule, tree-level allowlist, filters, collision suffixes, fallback), orphaned-event detection, success gate, payload builder, payload-key derivation from path incl. change_pending (standalone)
php modules_v4/cronjob/tests/test-event-queue.php    # event-queue coalescing: newest per event name, name cap, ordering (standalone)
php modules_v4/cronjob/tests/test-i18n-mark.php      # i18n: MoreI18N::translate identity, manifest literals/shape unchanged, translateJobTitle % guard (standalone)
```
