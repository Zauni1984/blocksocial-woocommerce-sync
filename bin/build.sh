#!/usr/bin/env bash
# Baut beide installierbaren Plugins aus dem gemeinsamen Quellcode:
#   dist/blocksocial-woocommerce-sync-<version>.zip          (Admin-Plugin)
#   dist/blocksocial-woocommerce-sync-partner-<version>.zip  (Partner-Plugin)
#
# Aufruf: bin/build.sh [ausgabe-verzeichnis]
set -euo pipefail

SRC="$(cd "$(dirname "$0")/.." && pwd)"
OUT="${1:-$SRC/dist}"
VERSION="$(sed -n "s/.*define( 'WCIS_VERSION', '\([^']*\)'.*/\1/p" "$SRC/includes/bootstrap.php")"

if [ -z "$VERSION" ]; then
	echo "Version nicht gefunden (includes/bootstrap.php)." >&2
	exit 1
fi

# Versionen müssen überall übereinstimmen.
for f in "$SRC/blocksocial-woocommerce-sync.php" "$SRC/partner/blocksocial-woocommerce-sync-partner.php"; do
	if ! grep -q "Version:[[:space:]]*$VERSION\$" "$f"; then
		echo "Versionskonflikt: $f entspricht nicht $VERSION" >&2
		exit 1
	fi
done
for f in "$SRC/readme.txt" "$SRC/partner/readme.txt"; do
	if ! grep -q "^Stable tag: $VERSION\$" "$f"; then
		echo "Versionskonflikt: $f (Stable tag) entspricht nicht $VERSION" >&2
		exit 1
	fi
done

# PHP-Syntax prüfen (falls PHP verfügbar).
if command -v php >/dev/null 2>&1; then
	while IFS= read -r -d '' f; do
		php -l "$f" >/dev/null || { echo "Syntaxfehler in $f" >&2; exit 1; }
	done < <(find "$SRC" -name '*.php' -not -path '*/dist/*' -print0)
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$OUT"

copy_common() {
	local dest="$1"
	mkdir -p "$dest"
	cp -R "$SRC/includes" "$SRC/assets" "$dest/"
	[ -d "$SRC/languages" ] && cp -R "$SRC/languages" "$dest/"
	cp "$SRC/uninstall.php" "$dest/"
	[ -f "$SRC/LICENSE" ] && cp "$SRC/LICENSE" "$dest/"
	find "$dest" -name '.DS_Store' -delete
}

# Admin-Plugin.
A="$TMP/blocksocial-woocommerce-sync"
copy_common "$A"
cp "$SRC/blocksocial-woocommerce-sync.php" "$SRC/readme.txt" "$A/"
[ -f "$SRC/README.md" ] && cp "$SRC/README.md" "$A/"

# Partner-Plugin.
P="$TMP/blocksocial-woocommerce-sync-partner"
copy_common "$P"
cp "$SRC/partner/blocksocial-woocommerce-sync-partner.php" "$SRC/partner/readme.txt" "$P/"

rm -f "$OUT/blocksocial-woocommerce-sync-$VERSION.zip" "$OUT/blocksocial-woocommerce-sync-partner-$VERSION.zip"
( cd "$TMP" && zip -rq "$OUT/blocksocial-woocommerce-sync-$VERSION.zip" blocksocial-woocommerce-sync )
( cd "$TMP" && zip -rq "$OUT/blocksocial-woocommerce-sync-partner-$VERSION.zip" blocksocial-woocommerce-sync-partner )

echo "Gebaut:"
echo "  $OUT/blocksocial-woocommerce-sync-$VERSION.zip"
echo "  $OUT/blocksocial-woocommerce-sync-partner-$VERSION.zip"
