=== BlockSocial WooCommerce Sync – Partner ===
Contributors: blocksocial
Tags: woocommerce, dropshipping, b2b, partner, stock sync
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 11.1
Stable tag: 3.1.1
License: MIT
License URI: https://opensource.org/licenses/MIT

Partner-Plugin für Partnershops im BlockSocial-Verbund: Produkte und Bestände kommen automatisch vom Hauptshop, Verkäufe werden zurückgemeldet. Die Vorgaben des Administrators sind geschützt.

== Description ==

Dieses Plugin verbindet deinen WooCommerce-Shop als **Partnershop** mit einem Hauptshop, der das Admin-Plugin „BlockSocial WooCommerce Sync" (ab v3.0) nutzt.

* **Einfache Einrichtung:** Verbindungscode vom Betreiber des Hauptshops einfügen – fertig.
* **Produkte holen:** Alle für dich freigegebenen Produkte mit einem Klick übernehmen (mit Fortschrittsbalken) – inkl. Texte, Bilder, Kategorien, Marken, Hersteller-Angaben, EAN, Grundpreis, Lieferzeit, Steuerklassen.
* **Bestände in Echtzeit:** Der Hauptshop gibt die Bestände vor. Verkäufe aus deinen Bestellungen werden automatisch zurückgemeldet (als Verkaufsmenge, nicht als Absolutwert – dadurch gehen keine Verkäufe verloren).
* **Angebote nach Wahl:** Angebotspreise des Hauptshops übernehmen – oder eigene Angebote machen.
* **Eigene Preise:** Preise nach dem Einspielen in % anpassen – nach oben oder unten, für alle Produkte oder je Kategorie, optional mit Rundung (z. B. auf ,99). Die Regeln bleiben bei Preis-Updates des Hauptshops erhalten (im vom Hauptshop erlaubten Rahmen).
* **Kleinunternehmer (§19):** Preise können als Bruttopreise übernommen werden.

= Sicherheit =

* Persönlicher Zugangsschlüssel (HMAC-SHA256-signierte Anfragen, Replay-Schutz).
* Der Partnershop spricht ausschließlich mit seinem Hauptshop.
* Alle Vorgaben des Hauptshops (Sync-Verhalten, Sortiment, Preis-Rahmen) sind schreibgeschützt.

== Installation ==

1. Plugin hochladen und aktivieren (WooCommerce muss aktiv sein).
2. WooCommerce → BlockSocial Partner → Verbindung: Verbindungscode einfügen → „Verbinden".
3. Reiter „Produkte": „Produkte vom Hauptshop holen".
4. Optional Reiter „Preise": eigene Auf-/Abschläge festlegen und anwenden.

Hinweis: Nicht gleichzeitig mit dem Admin-Plugin „BlockSocial WooCommerce Sync" aktivieren.

== Changelog ==

= 3.1.1 =
* Produkte werden mit vollständigem Kategorie-Pfad übernommen: fehlende Kategorien entstehen unter der richtigen Oberkategorie statt als neue Hauptkategorie.
* Gemeinsame Fehlerbehebungen mit dem Admin-Plugin 3.1.1 (Sync-Filter inkl. Unterkategorien).

= 3.1.0 =
* Neue Option „Angebotspreise des Hauptshops übernehmen" (Reiter Produkte → Eigene Einstellungen). Ausgeschaltet werden nur reguläre Preise übernommen – eigene Angebotspreise bleiben unangetastet.

= 3.0.0 =
* Erste Version des Partner-Plugins.
