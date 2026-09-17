#!/usr/bin/env bash
# Build a WordPress.org submission tree from this checkout.
# Usage: bin/export-wporg-tree.sh [destination-dir]
# Default destination: /tmp/agent-builder
# Also writes a sibling zip (e.g. /tmp/agent-builder.zip). That zip is a
# build artifact — gitignored, never commit it.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="${1:-/tmp/agent-builder}"
DEST="$(mkdir -p "$DEST" && cd "$DEST" && pwd)"
IGNORE="$ROOT/.distignore"

if [[ ! -f "$IGNORE" ]]; then
	echo "Missing .distignore" >&2
	exit 1
fi

# Refuse to rm -rf anything that isn't a scoped build-output directory —
# a bare "." or a typo'd path must never resolve to the repo root, /, or $HOME.
case "$DEST" in
	"$ROOT"|"/"|"$HOME")
		echo "Refusing to build into $DEST (too broad)." >&2
		exit 1
		;;
esac

rm -rf "$DEST"
mkdir -p "$DEST"

# Translate .distignore lines into rsync --exclude patterns.
EXCLUDES=( --exclude '.git/' )
while IFS= read -r line || [[ -n "$line" ]]; do
	line="${line%%#*}"
	line="$(echo "$line" | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//')"
	[[ -z "$line" ]] && continue
	EXCLUDES+=( --exclude "$line" )
done < "$IGNORE"

rsync -a "${EXCLUDES[@]}" "$ROOT/" "$DEST/"

# .distignore excludes vendor/ (the dev checkout's copy may include
# require-dev packages) — rebuild it here with production-only dependencies.
#
# PDF generation/reading (mpdf, smalot/pdfparser) is self-hosted-only: mpdf
# alone bundles ~85 fonts (~88 MB) for its Unicode auto-font-select mode,
# which dominates what would otherwise be a small plugin. create_pdf,
# read_pdf, get_pdf_info, and merge_pdfs already detect a missing library
# via is_available()/get_unavailable_reason() and report themselves
# unavailable rather than erroring — that mechanism is what makes it safe to
# simply not ship these two packages here, no tool code needs to change.
#
# phpoffice/phpword is excluded for a different reason, not size: its
# LICENSE.md is GNU Lesser General Public License *version 3* with no "or
# later" clause (LGPL-3.0-only), which cannot be validly combined into this
# plugin's GPL-2.0-or-later codebase (WordPress.org Guideline 1) — verified
# against every package that actually ships here, not just top-level
# requires (see docs/SUBMISSION-NOTES.md §7). create_docx, html_to_docx, and
# read_docx already have the exact same is_available()/get_unavailable_reason()
# guard as the PDF tools, so removing the library here is enough — no tool
# code changes. phpoffice/math (phpword's own dependency, MIT, otherwise
# fine) drops out automatically once phpword is gone. Spreadsheet tools
# (phpspreadsheet, MIT) and its zipstream dependency (MIT) stay.
php -r '
	$json = json_decode( file_get_contents( $argv[1] . "/composer.json" ), true );
	unset( $json["require"]["mpdf/mpdf"], $json["require"]["smalot/pdfparser"], $json["require"]["phpoffice/phpword"] );
	file_put_contents( $argv[1] . "/composer.json", json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
' "$DEST"
# The trimmed composer.json no longer matches composer.lock, so resolve
# fresh rather than installing from a stale lock — composer.lock is never
# copied in and never part of the shipped plugin either way.
( cd "$DEST" && composer update --no-dev --optimize-autoloader --no-interaction )
rm -f "$DEST/composer.lock"

# Fail loudly rather than silently ship a GPL-2-incompatible dependency again
# if a future change to composer.json re-adds phpword (or anything else)
# without updating the exclusion list above.
if [[ -d "$DEST/vendor/phpoffice/phpword" ]]; then
	echo "ERROR: vendor/phpoffice/phpword present in the WP.org tree — this is LGPL-3.0-only and incompatible with Guideline 1. Check composer.json / the unset() list above." >&2
	exit 1
fi

# Zip sits beside the tree as a build artifact (gitignored — never commit it).
PARENT="$(dirname "$DEST")"
BASE="$(basename "$DEST")"
ZIP="$PARENT/${BASE}.zip"
rm -f "$ZIP"
( cd "$PARENT" && zip -r -q -X "$ZIP" "$BASE" )

FILE_COUNT="$(find "$DEST" -type f | wc -l | tr -d ' ')"
ZIP_SIZE="$(du -h "$ZIP" | cut -f1)"
echo "Exported WP.org tree to $DEST"
echo "Built zip $ZIP ($ZIP_SIZE, $FILE_COUNT files)"
