#!/usr/bin/env bash
# Aktualisiert die Übersetzungen nach Textänderungen im Code (benötigt WP-CLI).
#
#   1. POT-Vorlage neu erzeugen
#   2. vorhandene PO-Dateien (en_US, fr_FR, es_ES, it_IT, nl_NL, pl_PL) abgleichen –
#      neue Texte erscheinen dort leer und können z. B. mit Poedit übersetzt werden
#   3. MO- und PHP-Dateien erzeugen, die WordPress lädt
#
# Aufruf: bin/i18n.sh   (WP-CLI als „wp" im PATH oder WP=/pfad/zu/wp setzen)
set -euo pipefail
WP="${WP:-wp}"
SRC="$(cd "$(dirname "$0")/.." && pwd)"

run() {
	local dir="$1" domain="$2" exclude="$3"
	"$WP" i18n make-pot "$dir" "$dir/languages/$domain.pot" --domain="$domain" --exclude="$exclude" \
		--headers='{"Language-Team":"BlockSocial <info@blocksocial.eu>","Report-Msgid-Bugs-To":"https://blocksocial.eu"}'
	"$WP" i18n update-po "$dir/languages/$domain.pot" "$dir/languages/"
	"$WP" i18n make-mo "$dir/languages/"
	"$WP" i18n make-php "$dir/languages/"
}

run "$SRC" blocksocial-woocommerce-sync "dist,docs,license-server"
for LS in "$SRC/license-server" "$SRC/../blocksocial-license-server"; do
	if [ -f "$LS/blocksocial-license-server.php" ]; then
		run "$LS" blocksocial-license-server ""
		break
	fi
done
