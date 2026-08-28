#!/bin/bash
# I18N - Update PO-/POT-files from source code (cronjob module)
#
# Translatable literals in cron-jobs.php are wrapped in
# MoreI18N::translate() (identity marker - the last qualified name component
# "translate" matches --keyword=translate below, so xgettext extracts them
# without translating at manifest load time).
#
# Strings already covered by the webtrees core POT are NOT extracted: the
# code wraps them in MoreI18N::xlate() (same runtime behaviour, different
# name -> invisible to xgettext). After a core update, compare
# resources/lang/messages.all.pot with the core POT and mask any newly
# covered msgids with MoreI18N::xlate() in the code.
SCRIPTDIR=$(dirname "$(realpath -s "${BASH_SOURCE:-$0}")")

PROJECT_ROOT=$(realpath "${SCRIPTDIR}/..")
LANG_DIR="$PROJECT_ROOT/resources/lang"
POT_FILE_ALL="$LANG_DIR/messages.all.pot"
POT_FILE_FILTERED="$LANG_DIR/messages.pot"

mkdir -p "$LANG_DIR"
cd "$PROJECT_ROOT" || exit 1

echo "📦 Generate POT-File: $POT_FILE_ALL"

# Erzeuge messages.all.pot mit relativen Pfaden
# (./vendor/* muss raus: dragonmantank/cron-expression ist Fremdcode)
xgettext -L PHP \
  --keyword=translate \
  --keyword=plural:1,2 \
  --add-comments=I18N \
  --from-code=utf-8 \
  --output="$POT_FILE_ALL" \
  $(find . -not -path "./util/*" -not -path "./vendor/*" -not -path "./tests/*" \( -name "*.php" -o -name "*.phtml" \))

echo "✅ POT-file created."


# Core-dedupe happens in the code (MoreI18N::xlate masks core-covered
# msgids), so the filtered POT is a plain copy of the full extraction.
# messages.all.pot is kept as the comparison artifact for future core
# updates (see header).
cp "$POT_FILE_ALL" "$POT_FILE_FILTERED"
POT_FILE="$POT_FILE_FILTERED"

exit 0
## po files are updated via Weblate to prevent conflicts
# init PO-files
for PO_FILE in "$LANG_DIR"/*.po; do
  lang=$(basename "${PO_FILE%.*}")

  if [[ -f "$PO_FILE" ]]; then
    echo "🔄 Update existing PO-file for [$lang]"
    msgmerge --update --backup=none "$PO_FILE" "$POT_FILE"
  else
    echo "🆕 Create new PO-file for [$lang]"
    msginit --input="$POT_FILE" --locale="$lang" --output-file="$PO_FILE" --no-translator
  fi
done

echo "✅ All language files are up to date."
exit 0
