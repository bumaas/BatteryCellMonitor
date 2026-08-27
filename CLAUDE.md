# Battery Cell Monitor — Projektwissen

Symcon-Modulbibliothek (bumaas), entstanden 08/2026 aus dem Zellmonitor-Skript
des nuc (Skript #56646, Projekt `E:\Desktop\Smart Home\Eigenes`). Zwei
Gerätemodule über einer gemeinsamen Basisklasse (`libs/CellMonitorBase.php`).

## Architektur

- **Basisklasse** `CellMonitorBase` (abstrakt, `IPSModuleStrict`):
  ModBus-TCP-Framing (MBAP, FC03/FC06) direkt auf dem Client Socket,
  Transaktions-ID-Matching im `RxBuffer` (Instanz-ID im High-Byte, damit
  mehrere Instanzen am selben Socket koexistieren), Semaphore **je Parent**
  (`CellMonitorBus_<ParentID>` — zwei Türme an einer BCU dürfen sich nicht
  überlappen), Variablenverwaltung, Schwellwertprüfung, Push mit Sperrzeiten.
- **Kindklassen** implementieren `readStatusValues()`, `readCellVoltages()`,
  optional `readModuleTemperatures()`, `registerVendorVariables()`.
- Timer laufen über `IPS_RequestAction($_IPS['TARGET'], 'TimerStatus'/'TimerMeasure', 0)`
  — dadurch prefix-unabhängig in der Basisklasse registrierbar.
- Byte-sichere Socket-Übergabe per `mb_convert_encoding(…, 'UTF-8', 'ISO-8859-1')`
  (das früher übliche `utf8_encode` ist seit PHP 8.2 deprecated).

## BYD (erprobt am HVM-Turm des nuc, 5 Module à 16 Zellen)

Protokoll nach sarnau/BYD-Battery-Box-Infos, am 25.08.2026 verifiziert:
- BCU spricht ModBus-TCP auf **Port 8080**, Unit-ID 1.
- Handshake: INDEX (0x0550, FC06) = BMS-Nr., CMD (0x0551, FC06) = 0x8100,
  STATUS (0x0551, FC03) pollen bis 0x8801; 0x4000 = ungültiger Index.
- Danach **4× denselben 65er-Block ab 0x0558** lesen (wanderndes Fenster);
  erstes Wort jeder Lesung ist Header. Zusammengesetzter Puffer (256 Worte):
  Zellen mV ab Wort 48, Temperaturen ab Wort 177 (2 Sensoren je Wort,
  4 Worte je Modul), Seriennummer Wort 34–45 (ASCII).
- Statusblock **0x0500 (1280), 20 Worte, ohne Handshake**: SOC, Max/Min-Zell-
  spannung ×0,01, SOH, Strom ×0,1 signed (negativ = Laden), Batteriespannung
  ×0,01, Zelltemperaturen, Wort 13 = Fehler-Bitmask, Wort 17 = Ladezyklen.
- Infoblock Register 14–17: BMS-Version, Config Word (Bits 0–3 Modulzahl,
  4–7 BMS-Anzahl), Batterietyp.
- **FC16-Kombi-Writes lehnt die BMU ab** — nur einzelne FC06.
- Offene Frage: BMS-Index 2 (zweiter Turm) ist ungetestet — Rainer (erpe,
  Forum `t/144307`) hat zwei Türme und wollte berichten.

## Marstek (vorbereitet, UNGETESTET — kein Gerät mit ModBus-Zugang vorhanden)

Registerkarte nach ViperRNMC/marstek_venus_modbus (`registers/e_v3.yaml`,
Stand 08/2026), Venus E v3:
- 16 Zellen ab Register **34018** (mV), Min/Max-Zelle 37008/37007 (mV)
- SOC 34002 ×0,1; Zyklen 34003; Spannung 30100 ×0,01; Strom 30101 ×0,1 signed;
  Leistung 30001 signed; Temperaturen 35000/35010/35011 ×0,1
- Alarm 36000 (2 Register), Fault 36100 (4 Register) — Bedeutung der Bits
  noch nicht dokumentiert, werden als Hex-String ausgegeben
- Venus E **v3 hat Ethernet direkt** (Port 502); v1/v2 brauchen eine
  RS485-Bridge (Elfin EW11, USR DR134) im ModBus-TCP-Modus. **v1/v2 haben
  teils andere Register** (`e_v12.yaml`, z. B. SOC 32104) — bei Bedarf als
  zweite Registerkarte nachrüsten.
- Burkhards vorhandene Venus E 3.0 (am MarstekShellyEmulator) und die geplante
  Zweitbatterie sind die Zielgeräte für den Ersttest.

## Teststand / offene Punkte (Stand 27.08.2026)

- Repo entsteht **außerhalb von `T:\modules`** (Urlaubsmodus: nuc scannt
  `T:\modules` als Modulverzeichnis; erst nach dem Urlaub dorthin umziehen).
- Noch **nicht gegen echte Hardware getestet** — nur `php -l`, JSON- und
  Locale-Check. Testplan nach dem Urlaub:
  1. Auf der **Testbox** installieren (git), Instanz gegen die BCU
     192.168.178.24:8080 anlegen. Achtung: der nuc hält bereits eine
     dauerhafte Socket-Verbindung zur BCU — zuerst klären, ob die BCU eine
     zweite TCP-Verbindung parallel akzeptiert; sonst Test in einem Fenster,
     in dem der nuc-Socket geschlossen ist.
  2. Vergleichsmessung gegen das Skript #56646 (gleiche Werte je Zelle?).
  3. Danach Skript #56646 + Blockabfrage #27108 + Dummy-Modul-Zweig auf dem
     nuc durch eine Modul-Instanz ersetzen (Archiv-Historie der bestehenden
     Variablen beachten — ggf. Variablen umziehen statt neu anlegen).
  4. Marstek-Modul an der vorhandenen Venus E 3.0 erproben (erst prüfen, ob
     ModBus/Ethernet dort aktiv ist).
- Presentations statt Profile (`VARIABLE_PRESENTATION_VALUE_PRESENTATION`
  mit SUFFIX/DIGITS); `ensureVariable()` aktiviert Archiv-Logging nur für
  **neu angelegte** numerische Variablen.
- Referenz-Checkliste: `T:\modules\BlindControl` (CI, locale-Check übernommen;
  `tests/check_locale.php` prüft hier zusätzlich `libs/*.php` mit).

## Konventionen

Version/Build in `library.json` pflegen (Commit-Subject
`<version> build <NN>: <Beschreibung>`); Details in der globalen `CLAUDE.md`.
