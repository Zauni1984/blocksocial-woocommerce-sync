=== BlockSocial WooCommerce Sync ===
Contributors: blocksocial
Tags: woocommerce, inventory, stock, sync, multishop, dropshipping, b2b
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 9.9
Stable tag: 2.7.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Enterprise-Grade WooCommerce-Plugin für Produkt- und Bestands-Synchronisation zwischen mehreren Shops in nahezu Echtzeit. Zuordnung per SKU, wählbarer Hauptshop. Baue dein eigenes Dropshipping-/B2B-Business auf.

== Description ==

BlockSocial WooCommerce Sync verbindet drei oder mehr WooCommerce-Shops zu einem Bestands- und Produkt-Verbund.
Verkauft ein Shop einen Artikel, wird der neue Lagerbestand sofort an alle übrigen
Shops übertragen (Zuordnung per SKU). Neue Produkte lassen sich 1:1 verteilen – ideal für
Dropshipping- und B2B-Netzwerke.

**Funktionen**

* Kopplung mehrerer Shops (3+) über die REST-API mit HMAC-signierten Anfragen.
* Ein wählbarer Hauptshop (Master) für die erste Voll-Synchronisation – jederzeit änderbar.
* Nahezu Echtzeit-Push bei jeder Bestandsänderung (Bestellung, Storno, manuelle Änderung).
* Zuordnung per SKU – einfache und variable Produkte (über Variations-SKUs) werden unterstützt.
* Optionaler Produkt-Sync inkl. Steuerstatus/Steuerklasse und Steuerklassen-Zuordnung.
* Slave-Pull: Neben-Shops können sich die Produkte des Hauptshops selbst holen.
* Automatischer Abgleich (Reconciliation) und Wiederholung fehlgeschlagener Zustellungen (Retry-Queue via Cron).
* Sync-Filter pro Shop (Kategorie, Marke, Einzelprodukte, Feld-Auswahl) mit Umfang-Vorschau.
* Produkte, die nur in einem Shop existieren, werden automatisch ignoriert.
* Moderne Admin-Oberfläche (App-Layout, Dark-Mode, Fortschrittsbalken), Verbindungstest und Protokoll.

== Installation ==

1. Ordner `blocksocial-woocommerce-sync` nach `wp-content/plugins/` hochladen (auf jedem Shop).
2. Plugin in jedem Shop aktivieren.
3. Im Hauptshop unter *WooCommerce → Lagerbestand-Sync* ein Netzwerk-Secret erzeugen.
4. In jedem Shop dasselbe Secret eintragen, alle Shop-URLs hinterlegen und den Hauptshop wählen.
5. Verbindung testen und anschließend im Hauptshop die erste Voll-Synchronisation starten.

== Upgrade Notice ==

= 2.0.0 =
Umbenennung von „WC Inventory Sync" zu „BlockSocial WooCommerce Sync". Interne REST-Namespaces und Options-Keys bleiben kompatibel – ein Update von 1.x läuft ohne Neukonfiguration.

== Changelog ==

= 2.7.0 =
* Hersteller-Adresse & EU-Bevollmächtigter: Der Hersteller-Sync überträgt jetzt auch die am Hersteller hinterlegten GPSR-Angaben – Hersteller-Adresse (`formatted_address`) und EU-Bevollmächtigter/Verantwortliche Person (`formatted_eu_address`) sowie die Term-Beschreibung. Fehlt der Hersteller beim Empfänger, wird er inkl. dieser Daten angelegt.
* EAN/GTIN-Sync als eigenes, separat wählbares Feld („EAN / GTIN"): überträgt die WooCommerce-Kennung `global_unique_id` sowie Germanizeds `_ts_gtin`/`_ts_mpn` – pro Produkt und Variation, durchgängig als Zeichenkette (keine gekürzten/abgeschnittenen Nummern mehr). Bisher wurde der EAN gar nicht als eigenes Feld übertragen.

= 2.6.0 =
* Hersteller-Sync (Germanized/Germanized Pro): Die Hersteller-Zuordnung eines Produkts wird jetzt mitübertragen. Eigenes, separat wählbares Feld („Hersteller"). Zuordnung per Slug (ersatzweise Name); fehlt der Hersteller beim Empfänger, wird er angelegt und Germanizeds Verknüpfung (`_manufacturer_slug`) gesetzt. Greift nur, wenn der Ziel-Shop eine Hersteller-Taxonomie hat.

= 2.5.0 =
* Germanized-Grundpreis-Sync: Die für Deutschland pflichtigen Grundpreis-Angaben (Einheit, Grundmenge, Inhalt, automatische Berechnung sowie die berechneten Grundpreise) werden jetzt mitübertragen – auch je Variation (z. B. Grundpreis pro Packungsgröße). Über die Feld-Auswahl an-/abschaltbar („Germanized: Grundpreis"). Enthält zusätzlich gängige Germanized-Angaben (Kurzbeschreibung Checkout, Mindestalter, Differenzbesteuerung, Service/Gebraucht-Kennzeichnung).
* Lieferzeit-Sync als eigenes, separat wählbares Feld („Lieferzeit"): die Germanized-Lieferzeit (Taxonomie-Begriff per Name, fehlende werden angelegt) wird pro Produkt und Variation übertragen. Beide Felder greifen nur, wenn der Ziel-Shop Germanized nutzt.

= 2.4.0 =
* Marken-Sync: Produktmarken werden jetzt mitübertragen. Pro Shop über die Feld-Auswahl im Reiter „Produkt-Sync" an-/abschaltbar („Marken"). Die Marken-Taxonomie wird automatisch erkannt (WooCommerce-Marken `product_brand`, Perfect Woocommerce Brands `pwb-brand`, YITH u. a.); bei mehreren registrierten Taxonomien wird die tatsächlich genutzte bevorzugt. Fehlende Marken werden beim Empfänger per Name angelegt; hat der Ziel-Shop keine Marken-Taxonomie, wird das Feld übersprungen.

= 2.3.1 =
* Variable Produkte ohne Eltern-SKU werden jetzt trotzdem synchronisiert: Die Zuordnung erfolgt ersatzweise über die SKUs der Variationen (statt das Produkt komplett zu überspringen). Betrifft Export/Pull und Massen-Übertragung. Einfache Produkte benötigen weiterhin eine SKU. Sicherheitsnetz für Fälle, in denen nur die Variationen SKUs tragen.

= 2.3.0 =
* Versandklassen-Sync: Die Versandklasse eines Produkts (und je Variation) lässt sich jetzt mitübertragen. Über die Feld-Auswahl im Reiter „Produkt-Sync" pro Shop an-/abschaltbar („Versandklasse"). Zuordnung per Slug, ersatzweise per Name; fehlt die Klasse beim Empfänger, wird sie angelegt (die Versandkosten je Klasse bleiben shop-spezifisch). Eine entfernte/leere Versandklasse wird 1:1 als „keine Versandklasse" übernommen.

= 2.2.1 =
* Lagerstatus wird beim Bestands-Sync jetzt 1:1 übertragen – inklusive „Lieferrückstand" (onbackorder). Bisher wurde nur „auf Lager / nicht auf Lager" gesendet, wodurch Produkte im Lieferrückstand beim Empfänger fälschlich als „nicht vorrätig" ankamen. Der echte Status und die Lieferrückstand-Einstellung (Nein/Anmerkung/Ja) werden mitgesendet und exakt übernommen. Abwärtskompatibel mit älteren Installationen.

= 2.2.0 =
* Kategorie-Ausschluss greift jetzt auch bei NEUEN eingehenden Produkten: Der Empfänger prüft die mitgesendeten Kategorien (inkl. Unterkategorien), sodass ausgeschlossene Kategorien (z. B. „Merch") beim Anlegen zuverlässig übersprungen werden – nicht nur bei bereits vorhandenen Produkten. Kategorienamen werden dafür immer mitgesendet.
* Bestandsverwaltung wird beim Produkt-Update nicht mehr fälschlich abgeschaltet, wenn der Sender das Feld „Lagerbestand" nicht überträgt (schützt den Bestands-Sync bei „Bestehende aktualisieren"/Re-Pull).
* Variationen ohne SKU werden übersprungen statt bei jedem Lauf als Dublette angelegt (Zuordnung erfolgt per SKU).
* Voll-Sync, Produkt-Massenübertragung und Retry-Queue haben jetzt Lauf-Sperren gegen überlappende Ausführung (kein Doppelzählen/Doppelversand durch zwei Tabs oder überlappende Cron-Läufe); Artikel-/ID-Listen laufen bei langen Jobs nicht mehr ab (kein stiller Datenverlust).
* Abgleich (Reconciliation) prüft jetzt auch SKUs, die es auf dem Hauptshop nicht gibt, aber auf mehreren Neben-Shops.
* Loop-Schutz wird bei Speicherfehlern zuverlässig zurückgesetzt (try/finally); unbekannter Veröffentlichungsstatus fällt sicher auf „Entwurf"; leeres Netzwerk-Secret löscht das vorhandene nicht mehr; kleinere Härtung der Vorschau-Ausgabe.

= 2.1.2 =
* Steuerklasse jetzt slug-unabhängig: Beim Produkt-Sync/Pull wird der effektive Steuersatz (z. B. 7 % / 19 %) mitgesendet. Findet der Empfänger den eingehenden Steuerklassen-Slug nicht (unterschiedliche Bezeichnungen zwischen Shops, z. B. „reduzierter-preis" vs. „reduced-rate"), ordnet er die passende lokale Steuerklasse automatisch über den Steuersatz zu – ohne manuelle Zuordnung. Reihenfolge: explizite Zuordnung → gleicher Slug → Steuersatz-Automatik → sonst unverändert lassen (nie fälschlich auf Standard).

= 2.1.1 =
* Robustheit Produkt-Sync/Pull: Übertragungen bleiben nicht mehr an einem einzelnen Produkt hängen. Der Bild-Import bricht langsame/tote Bild-URLs nach 15 s ab (statt bis zu 300 s zu blockieren) und begrenzt die Bildanzahl. Fehlerhafte Produkte werden übersprungen und protokolliert, statt den ganzen Lauf abzubrechen. Der Fortschritt wird nach jedem Produkt bzw. jeder Seite gespeichert – ein abgebrochener Lauf setzt exakt dort fort, statt neu zu starten. Einzelne Produkt-Übertragungen haben ein hartes Timeout (25 s); die Oberfläche bricht nach mehreren Fehlversuchen mit Meldung ab, statt endlos zu drehen.

= 2.1.0 =
* Sicherheits-Härtung (Defense-in-Depth): erzwingt ein starkes Netzwerk-Secret (mind. 16 Zeichen) beim Speichern – zu kurze Secrets werden nicht übernommen. Topologie-Änderungen (Shop-Liste/Hauptshop über /config) werden nur noch vom konfigurierten Hauptshop akzeptiert. Eingehende Aufzähl-Felder (Steuer-/Lagerstatus, Lieferrückstand) werden gegen Whitelists geprüft. SSRF-Schutz beim Bild-Import (nur externe http(s)-URLs, keine internen/Loopback-Adressen). Alle REST-Endpunkte bleiben unverändert HMAC-SHA256-signiert (Token-Pflicht) – die Änderungen sind abwärtskompatibel.

= 2.0.0 =
* Umbenennung zu „BlockSocial WooCommerce Sync" (Ordner, Hauptdatei, Text-Domain). Interne REST-Namespaces und Options-Keys bleiben für bestehende Installationen kompatibel – ein Update von 1.x ist ohne Neukonfiguration möglich.
* Neue Enterprise-Positionierung: Produkt- und Bestands-Sync für Dropshipping-/B2B-Netzwerke.

= 1.7.0 =
* Steuer-Sync: Steuerstatus und Steuerklasse werden als eigenes Feld übertragen (getrennt vom Preis) – auch für Variationen. Neue „Steuerklassen-Zuordnung" (Empfängerseite) übersetzt abweichende Slugs (z. B. „reduzierter-preis" ⇒ „reduced-rate"); unbekannte, nicht zugeordnete Slugs werden übersprungen statt fälschlich auf Standard zu fallen.
* Produkte vom Hauptshop holen (Pull): Ein Neben-Shop kann die erste Produkt-Übernahme selbst starten und sich die Produkte des Hauptshops holen – ohne dass der Hauptshop an alle Shops verteilen muss. Chunk-basiert mit Fortschrittsbalken.

= 1.6.0 =
* Komplett überarbeitete, moderne Admin-Oberfläche: App-Layout mit sticky Topbar (Live-Status), seitlicher Tab-Navigation (kein langes Scrollen), Karten, Toggle-Switches, Chips, animierten Fortschrittsbalken und Dark-Mode (folgt dem System-Theme). Rein CSS/JS, keine zusätzlichen Bibliotheken.

= 1.5.0 =
* Kategorie-Ausschluss: ganze Produktkategorien lassen sich vom Sync ausschließen (harter Ausschluss, gilt aus- und eingehend).
* Vorschau „Umfang anzeigen": zeigt anhand der aktuellen (auch ungespeicherten) Auswahl, wie viele Produkte in den Sync-Umfang fallen – inkl. Beispielliste.

= 1.4.0 =
* Sync-Filter pro Shop: Auswahl, welche Produkte synchronisiert werden – nach Kategorie, nach Marke (automatische Erkennung gängiger Marken-Taxonomien) und per Einzelprodukt-Auswahl. Einzelne Produkte lassen sich hart ausschließen (gilt aus- und eingehend).
* Feld-Auswahl für den Produkt-Sync: frei wählbar, welche Felder (z. B. Preis, Beschreibung, Bilder, Status) übertragen werden – so kann jeder Shop z. B. eigene Preise behalten.
* Filter gilt für Bestands- und Produkt-Sync sowie für den Abgleich.

= 1.3.0 =
* Optionaler Produkt-Sync: neue Produkte werden 1:1 an alle Shops übertragen (einfache und variable Produkte), inklusive Veröffentlichungsstatus (veröffentlicht/privat/Entwurf). Zuordnung per SKU.
* Quelle wählbar (nur Hauptshop oder jeder Shop), Bilder optional mitübertragen, bestehende Produkte optional aktualisieren.
* „Alle Produkte jetzt übertragen"-Button mit Fortschrittsbalken (chunk-basiert, kein Timeout) zum Befüllen neuer Shops.
* Guaranteed Delivery über die Retry-Queue (nun mit Endpoint-Unterstützung); DB-Upgrade-Routine für bestehende Installationen.

= 1.2.0 =
* Automatischer Abgleich (Reconciliation): periodischer Konsistenz-Check (stündlich/6h/täglich), der Bestands-Abweichungen zwischen den Shops erkennt und Korrekturen nachreicht – ideal, wenn ein Shop kurz nicht erreichbar war.
* Konflikt-Strategie wählbar: „Niedrigster Bestand gewinnt" (schützt vor Überverkauf) oder „Hauptshop maßgeblich".
* Nicht erreichbare Shops beim Abgleich landen in der Retry-Queue und werden später automatisch nachgezogen.
* Manueller „Jetzt abgleichen"-Button und Statusanzeige des nächsten Abgleichs.

= 1.1.0 =
* Voll-Synchronisation als abbruchsicherer Hintergrund-Job mit Prozent-Fortschrittsbalken (Live-Anzeige, kein PHP-Timeout).
* Einstellbare Batchgröße (1–500) und HTTP-Timeout (5–60 s).
* Nicht erreichbare Shops werden beim Voll-Sync übersprungen (kein Blockieren).

= 1.0.0 =
* Erste Version.
