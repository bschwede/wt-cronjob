#!/bin/bash
set -e
echo "-= Create release archive (cronjob) =-"
SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")
SRCDIR=$(realpath -m "$SCRIPTDIR/..")
DSTDIR=$(realpath -m "$SCRIPTDIR/../dist/cronjob")

# Version: single source of truth is CronjobModule::customModuleVersion()
VER=$(grep -A1 "function customModuleVersion" "$SRCDIR/src/CronjobModule.php" \
  | grep -oP "return '\K[0-9]+\.[0-9]+\.[0-9]+" || true)
[[ -n "$VER" ]] || { echo "ERROR: version not found in src/CronjobModule.php"; exit 1; }

# The bundled cron library must be present (vendor/* is .gitignore'd in the
# module repo - a fresh clone would otherwise produce a broken archive).
[[ -d "$SRCDIR/vendor/dragonmantank/cron-expression/src" ]] || {
  echo "ERROR: vendor/dragonmantank/cron-expression missing (.gitignore'd - restore before archiving)";
  exit 1;
}

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
vendor/autoload.php
vendor/composer
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
