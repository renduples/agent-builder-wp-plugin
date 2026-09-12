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
# Spreadsheet/DOCX tools (phpspreadsheet, phpword) and zipstream stay.
php -r '
	$json = json_decode( file_get_contents( $argv[1] . "/composer.json" ), true );
	unset( $json["require"]["mpdf/mpdf"], $json["require"]["smalot/pdfparser"] );
	file_put_contents( $argv[1] . "/composer.json", json_encode( $json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
' "$DEST"
# The trimmed composer.json no longer matches composer.lock, so resolve
# fresh rather than installing from a stale lock — composer.lock is never
# copied in and never part of the shipped plugin either way.
( cd "$DEST" && composer update --no-dev --optimize-autoloader --no-interaction )
rm -f "$DEST/composer.lock"

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
