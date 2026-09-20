#!/bin/bash
set -e
echo "-= Create release archive (cronjob) =-"
SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SRCDIR=$(realpath -m "$SCRIPTDIR/..")
DSTDIR=$(realpath -m "$SCRIPTDIR/../dist/cronjob")

# Version: single source of truth is CronjobModule::CUSTOM_VERSION
VER=$(grep -A1 "public const CUSTOM_VERSION" "$SRCDIR/src/CronjobModule.php" \
  | grep -oP "= '\K[0-9]+\.[0-9]+\.[0-9]+[\-A-Za-z0-9]*" || true)
[[ -n "$VER" ]] || { echo "ERROR: version not found in src/CronjobModule.php"; exit 1; }

# The bundled cron library must be present (vendor/* is .gitignore'd in the
# module repo - a fresh clone would otherwise produce a broken archive).
[[ -d "$SRCDIR/vendor/dragonmantank/cron-expression/src" ]] || {
  echo "ERROR: vendor/dragonmantank/cron-expression missing (.gitignore'd - restore before archiving)";
  exit 1;
}

# Option Y: the shared lib (bschwede/wt-shared-libs) and its composer
# installed.php (the version-skew source) must also be present.
[[ -d "$SRCDIR/vendor/bschwede/wt-shared-libs" ]] || {
  echo "ERROR: vendor/bschwede/wt-shared-libs missing (Option Y - run composer install before archiving)";
  exit 1;
}
[[ -f "$SRCDIR/vendor/composer/installed.php" ]] || {
  echo "ERROR: vendor/composer/installed.php missing (Option Y version skew - run composer install)";
  exit 1;
}

# Compile PO translations into the PHP runtime fast path and keep
# latest-version.txt in sync with CronjobModule::CUSTOM_VERSION.
command -v php >/dev/null 2>&1 || {
  echo "ERROR: php CLI required (util/compile-po.php)";
  exit 1;
}
php "$SRCDIR/util/compile-po.php" "$SRCDIR"

[[ ! -d "$DSTDIR" ]] && mkdir -p "$DSTDIR"

exclude_file=$(mktemp)
cat << EOT > "$exclude_file"
.vscode
node_modules
dist
.git*
util
tests
composer.json
latest-version.txt
vendor/autoload.php
resources/img
resources/lang/*.po*
resources/lang/*.mo
resources/views/*.phtml.~*
.weblate
requirements.txt
.venv
.env
EOT

cd "$SRCDIR" || exit 1
rsync -a --delete --exclude-from="$exclude_file" . "$DSTDIR"
rm "$exclude_file"

cd "$DSTDIR/.." || exit 1
zipfile="cronjob_v${VER}.zip"
[[ -f "$zipfile" ]] && rm "$zipfile"
zip -rq "$zipfile" cronjob
rm -rf "$DSTDIR"
echo "archive: $(realpath -m "$DSTDIR/..")/$zipfile"
