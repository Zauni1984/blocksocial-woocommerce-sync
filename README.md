# BlockSocial WooCommerce Sync

**Enterprise-Grade WooCommerce-Plugin für Produkt- und Bestands-Synchronisation.**
Baue dein eigenes Dropshipping-/B2B-Business auf.

Ein WordPress/WooCommerce-Plugin, das **Produkte und Lagerbestände mehrerer Shops in nahezu
Echtzeit synchronisiert**. Verkauft ein Shop einen Artikel, wird der neue Bestand sofort an
alle übrigen Shops übertragen; neue Produkte lassen sich 1:1 verteilen.

> Beispiel: Shop A hat 5 Stück von Produkt B. Shop A verkauft 2 → alle verbundenen Shops
> zeigen kurz darauf **3** verbleibende Stück an.

Die Plugin-Dateien liegen im **Wurzelverzeichnis** dieses Repositorys – der Ordnername für die Installation lautet `blocksocial-woocommerce-sync`.

> **Hinweis zum Update von 1.x („WC Inventory Sync"):** Ordner, Hauptdatei und Text-Domain
> heißen ab 2.0.0 `blocksocial-woocommerce-sync`. Die internen REST-Namespaces und
> Options-Keys bleiben unverändert – bestehende Verbünde synchronisieren nach dem Update
> ohne Neukonfiguration weiter.

## Kernfunktionen

| Anforderung | Umsetzung |
|---|---|
| Shops über API/gängige Methode koppeln | WooCommerce-/eigene **REST-API** mit **HMAC-SHA256-signierten** Anfragen und gemeinsamem Netzwerk-Secret |
| Hauptshop wählbar | **Master-Auswahl** im Backend; startet die erste Voll-Synchronisation |
| 3 oder mehr Shops | Beliebig viele Shops im „Hub & Spoke"-Verbund |
| Hauptshop später änderbar | Master jederzeit umstellbar + „Konfiguration an alle Shops verteilen" |
| Echtzeit nach Verkauf | Push bei jeder Bestandsänderung (Hook `woocommerce_*_set_stock`) am Request-Ende via `fastcgi_finish_request()` |
| Zuordnung per SKU | Matching ausschließlich über **SKU** – funktioniert auch für Variationen |
| Einfache + variable Produkte | Einfache Produkte und Variationen (eigene SKUs) werden erfasst |
| Nur in einem Shop vorhandene Produkte | Werden beim Empfänger **ignoriert** (SKU nicht gefunden → übersprungen) |
| Nachreichen bei kurzem Ausfall | **Retry-Queue** (Minuten-Cron) + **periodischer Abgleich** (stündlich/6h/täglich), der Drift erkennt und Korrekturen nachreicht |
| Neue Produkte 1:1 übertragen *(optional)* | **Produkt-Sync**: einfache & variable Produkte inkl. **Status** (veröffentlicht/privat/Entwurf), per SKU; automatisch + Massen-Button mit Fortschritt |
| Auswählen, was synchronisiert wird | **Sync-Filter** pro Shop: nach Kategorie, Marke, Einzelprodukt; Ausschlussliste; **Feld-Auswahl** (z. B. Preis) für den Produkt-Sync |
| Steuer 1:1 übertragen | **Steuerstatus & Steuerklasse** als eigenes Feld (auch für Variationen); **Steuerklassen-Zuordnung** für abweichende Slugs zwischen Shops |
| Erst-Sync auch vom Neben-Shop | **Pull**: ein Neben-Shop holt sich die Produkte des Hauptshops selbst – ohne dass der Hauptshop an alle verteilt |

## Funktionsweise

```
                 ┌──────────────┐   Bestellung: 5 → 3
                 │   Shop A      │──────────────┐
                 │ (Hauptshop)   │              │  signierter POST /wp-json/wc-inventory-sync/v1/stock
                 └──────────────┘              │  { sku: "B", stock: 3 }
                        ▲                        ▼
        signierter Push │              ┌──────────────┐   ┌──────────────┐
                        └──────────────│   Shop B      │   │   Shop C      │
                                       │ setzt B = 3   │   │ setzt B = 3   │
                                       └──────────────┘   └──────────────┘
```

1. **Erkennung:** Ändert sich ein Bestand (Verkauf, Storno, manuelle Anpassung), feuert der
   WooCommerce-Hook `woocommerce_product_set_stock` / `woocommerce_variation_set_stock`.
2. **Versand:** Am Ende der Anfrage (nach Auslieferung der Seite an den Kunden) sendet das
   Plugin den **absoluten neuen Bestand** signiert an alle Peer-Shops.
3. **Anwendung:** Der Empfänger sucht das Produkt per **SKU**, setzt den Bestand und
   verhindert per Sperre eine Rück-Synchronisation (keine Endlosschleife).
4. **Zuverlässigkeit:** Fehlgeschlagene Zustellungen landen in einer **Retry-Queue**, die
   ein Minuten-Cron erneut abarbeitet.

Es werden **absolute Werte** übertragen (nicht Deltas) – dadurch ist das System
selbstheilend: geht eine Nachricht verloren, korrigiert die nächste Änderung den Bestand.

## Installation

Das Plugin wird auf **jedem** beteiligten Shop installiert:

1. Ordner `blocksocial-woocommerce-sync/` nach `wp-content/plugins/` kopieren.
2. Plugin unter *Plugins* aktivieren (legt DB-Tabellen und Cron-Jobs an).
3. **Hauptshop einrichten:** *WooCommerce → Lagerbestand-Sync*
   - „Netzwerk-Secret" **neu erzeugen** und kopieren.
   - Alle Shop-URLs unter „Verbundene Shops" eintragen.
   - Unter „Hauptshop (Master)" diesen Shop wählen.
   - Speichern.
4. **Weitere Shops einrichten:** dasselbe Netzwerk-Secret eintragen, dieselben Shop-URLs
   hinterlegen, denselben Hauptshop wählen. (Alternativ im Hauptshop „Konfiguration an alle
   Shops verteilen" nutzen.)
5. Pro Shop **„Verbindung testen"** klicken – es sollte „Verbunden mit …" erscheinen.
6. Im Hauptshop **„Erste Voll-Synchronisation starten"** – überträgt alle Bestände an die
   übrigen Shops.

Ab jetzt läuft die laufende Synchronisation automatisch bei jedem Verkauf.

## Automatischer Abgleich (Reconciliation)

Über die Retry-Queue hinaus prüft der **Hauptshop** periodisch (einstellbar: stündlich /
alle 6 h / täglich) die Bestände aller Shops und **reicht Korrekturen nach**, falls ein Shop
zwischenzeitlich nicht erreichbar war und eine Änderung verpasst hat.

- **Ablauf:** Der Hauptshop ruft von jedem Shop den Bestand ab (`GET /inventory`, per SKU),
  vergleicht und verteilt Korrekturen. Nicht erreichbare Shops landen in der Retry-Queue und
  werden später automatisch nachgezogen.
- **Konflikt-Strategie:**
  - **Niedrigster Bestand gewinnt** (Standard) – schützt vor Überverkauf: verpasste Verkäufe
    werden sicher nachgezogen.
  - **Hauptshop maßgeblich** – der Wert des Hauptshops wird verteilt.
- **Manuell:** Button „Jetzt abgleichen" unter *Aktionen* startet den Abgleich sofort.
- **Voraussetzung:** aktiver WordPress-Cron (WP-Cron). Nach einem **Wareneingang/Restock**
  im Hauptshop die „Voll-Synchronisation" nutzen, um erhöhte Bestände zu verteilen.

## Produkt-Sync (neue Produkte 1:1 übertragen)

Optionale Funktion (Standard **aus**), um neue Produkte automatisch an alle Shops zu verteilen.

- **Unterstützt:** einfache und variable Produkte (mit Variationen), Titel, Beschreibung,
  Preise, Kategorien/Schlagwörter, benutzerdefinierte Attribute, Bilder (optional) und den
  **Veröffentlichungsstatus** (veröffentlicht / privat / Entwurf) – 1:1, Zuordnung per SKU.
- **Quelle:** nur Hauptshop (empfohlen) oder jeder Shop.
- **Bestehende Produkte:** bleiben standardmäßig unangetastet (nur der Lagerbestand wird
  weiter synchronisiert). Optional lassen sich vorhandene Produkte inkl. Status laufend spiegeln.
- **Erstbefüllung:** Button „Alle Produkte jetzt an alle Shops übertragen" mit
  Fortschrittsbalken (chunk-basiert, kein Timeout).
- **Zuverlässig:** Auslieferung über die Retry-Queue – bei kurzem Ausfall wird nachgereicht.
- **Wichtig:** Der Produkt-Sync muss auch auf jedem **Empfänger-Shop** aktiviert sein.
  Globale Attribut-Taxonomien werden auf dem Zielshop als produkteigene Attribute angelegt.

## Sync-Filter: Welche Produkte werden synchronisiert?

Jeder Shop legt selbst fest, welche seiner Produkte am Sync teilnehmen (gilt für
**Bestands- und Produkt-Sync** sowie den Abgleich):

- **Umfang:** „Alle Produkte" oder „Nur ausgewählte".
- **Nach Kategorie** – eine oder mehrere Produktkategorien.
- **Nach Marke** – erkennt gängige Marken-Taxonomien automatisch (WooCommerce Brands,
  Perfect Brands, YITH u. a.).
- **Einzelne Produkte einschließen** – gezielte Produktsuche (WooCommerce-Select2).
- **Einzelne Produkte ausschließen** – harter Ausschluss: diese Produkte werden nie
  verändert, weder ausgehend noch eingehend.
- **Kategorien ausschließen** – ganze Produktkategorien hart ausschließen (hat Vorrang vor
  allen Einschluss-Kriterien).

Im Modus „Nur ausgewählte" wird ein Produkt synchronisiert, sobald **mindestens ein**
Kriterium zutrifft (Einzelauswahl **oder** Kategorie **oder** Marke) – sofern es nicht
ausgeschlossen ist.

**Vorschau:** Der Button „Umfang anzeigen" berechnet anhand der aktuellen (auch
ungespeicherten) Auswahl, wie viele Produkte in den Sync-Umfang fallen, und zeigt eine
Beispielliste.

### Feld-Auswahl (welche Attribute übertragen werden)

Für den Produkt-Sync lässt sich wählen, welche Felder übertragen werden: **Preis**
(regulär & Angebot), Beschreibung, Kurzbeschreibung, Bilder, Kategorien, Schlagwörter,
Attribute, Maße/Gewicht, Status, Lagerbestand. Beispiel: Haken bei „Preis" entfernen,
damit jeder Shop **eigene Preise** behalten kann. Der Produktname wird zur Zuordnung
immer mitgesendet.

## Hauptshop wechseln

1. In einem Shop unter „Hauptshop (Master)" den neuen Shop auswählen und speichern.
2. „Konfiguration an alle Shops verteilen" klicken – die Auswahl wird an alle Peers übernommen.
3. Optional im neuen Hauptshop „Erste Voll-Synchronisation starten", um ihn als Quelle zu setzen.

## Voraussetzungen

- WordPress 5.8+, WooCommerce 5.0+, PHP 7.4+
- Alle Produkte, die synchronisiert werden sollen, benötigen in **allen** Shops **dieselbe SKU**.
- Die Shops müssen sich gegenseitig per HTTPS über die REST-API erreichen können.

## Sicherheit

- Jede Anfrage wird mit **HMAC-SHA256** über das gemeinsame Netzwerk-Secret signiert.
- Zeitstempel-Prüfung (±5 Min.) als Replay-Schutz.
- REST-Endpunkte lehnen Anfragen ohne gültige Signatur ab (HTTP 401).

## Dateien

```
blocksocial-woocommerce-sync.php     # Bootstrap, Konstanten, HPOS-Kompatibilität
├── uninstall.php                  # Aufräumen bei Deinstallation
├── readme.txt                     # WordPress-Plugin-Readme
├── includes/
│   ├── class-wcis-plugin.php      # Zentrale Klasse (Wiring)
│   ├── class-wcis-install.php     # Tabellen & Cron
│   ├── class-wcis-settings.php    # Optionen & Helfer
│   ├── class-wcis-sync-engine.php # Kern: Erkennung, Verteilung, Anwendung
│   ├── class-wcis-client.php      # Signierte HTTP-Requests
│   ├── class-wcis-rest-controller.php # REST-Endpunkte (Empfang)
│   ├── class-wcis-queue.php       # Retry-Queue
│   ├── class-wcis-logger.php      # Protokoll
│   ├── class-wcis-admin.php       # Backend & AJAX
│   └── views/settings-page.php    # Einstellungsseite
└── assets/                        # admin.css, admin.js
```

## Steuer-Sync & Steuerklassen-Zuordnung

Steuerstatus und Steuerklasse werden als **eigenes Feld** übertragen (getrennt vom Preis,
auch für Variationen). Nutzen zwei Shops für dieselbe Steuer (z. B. 7 %) **unterschiedliche
Steuerklassen-Slugs** (etwa `reduzierter-preis` vs. `reduced-rate`), lässt sich das per
**Steuerklassen-Zuordnung** (Reiter „Produkt-Sync", Empfängerseite) übersetzen:

```
reduzierter-preis=reduced-rate
```

Eine Zuordnung pro Zeile. Unbekannte, nicht zugeordnete Slugs werden **übersprungen** (die
Steuerklasse bleibt unverändert), statt fälschlich auf „Standard" (voller Satz) zu fallen.

## Produkte vom Hauptshop holen (Pull)

Neben dem Verteilen vom Hauptshop kann ein **Neben-Shop** die erste Produkt-Übernahme
**selbst starten**: Reiter „Aktionen" → *„Produkte vom Hauptshop holen"*. Der Neben-Shop
lädt die Produkte des Hauptshops seitenweise (Fortschrittsbalken) und legt sie lokal an –
ohne dass der Hauptshop an alle Shops verteilen muss. Voraussetzung: „Produkt-Sync aktiv"
auf dem Neben-Shop.

## Autor

**BlockSocial UG (haftungsbeschränkt)** — https://blocksocial.eu — info@blocksocial.eu

## Lizenz

MIT – siehe [LICENSE](LICENSE). © 2026 BlockSocial UG (haftungsbeschränkt).
