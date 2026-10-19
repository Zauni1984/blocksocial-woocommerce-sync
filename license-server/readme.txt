=== BlockSocial Lizenzserver ===
Contributors: blocksocial
Tags: woocommerce, license, license keys, software licensing, updates
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 11.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verkauf und Verwaltung von Plugin-Lizenzen über WooCommerce – für blocksocial.eu.

== Description ==

* **Lizenzschlüssel beim Kauf:** Sobald eine Bestellung bezahlt ist, erhält jede Position mit Lizenzprodukt einen Schlüssel (BSWS-XXXXX-XXXXX-XXXXX-XXXXX) – in der Bestellbestätigung, auf der Bestellseite und unter „Mein Konto → Lizenzen".
* **Lizenzstufen je Produkt oder Variante:** Plugin, Stufe, Plätze (verbundene Shops, 0 = unbegrenzt) und Laufzeit (Monate, 0 = lebenslang).
* **Kundenkonto „Lizenzen":** Schlüssel, Status, belegte Plätze, verbundene Shops, Downloads der aktuellen Version, Verlängern, Upgrade (Differenzpreis), Hauptshop freigeben (Umzug). Gastkäufe werden über die E-Mail-Adresse automatisch zugeordnet.
* **Verlängerung & Upgrade über den Warenkorb:** Verlängerung mit einstellbarem Rabatt, Upgrade zum Differenzpreis; der Schlüssel bleibt gleich.
* **Erstattung/Storno:** Lizenzen der Bestellung werden automatisch widerrufen.
* **Automatische Updates:** Das neueste GitHub-Release (privates Repository, Token bleibt auf dem Server) wird stündlich übernommen und nur an lizenzierte Shops über signierte, zeitlich begrenzte Links ausgeliefert. Alternativ manueller ZIP-Upload.
* **Admin:** Lizenzen suchen, bearbeiten (Plätze, Ablauf, Status), manuell anlegen, Hauptshop freigeben; Releases-Übersicht.
* **REST-API** `/wp-json/bsls/v1`: activate, deactivate, check, info, download – mit Begrenzung der Anfragen je IP.
* HPOS-kompatibel. Mehrsprachig (DE, EN, FR, ES, IT, NL, PL).

== Installation ==

1. ZIP hochladen und aktivieren (WooCommerce erforderlich). Danach Einstellungen → Permalinks → Speichern.
2. WooCommerce → Lizenzen → Releases & Updates: GitHub-Repository und ein Fine-grained Token (nur dieses Repository, „Contents: Read-only") eintragen.
3. Produkt (z. B. variabel mit Standard/Premium/Enterprise) als „Virtuell" anlegen und je Variante den Abschnitt „BlockSocial-Lizenz" ausfüllen.

Ausführlich: docs/VERKAUF.md im Plugin-Repository.

== Changelog ==

= 1.0.0 =
* Erste Version.
