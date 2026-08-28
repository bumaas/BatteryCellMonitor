# Battery Cell Monitor — Projektwissen

Symcon-Modulbibliothek (bumaas), entstanden 08/2026 aus dem Zellmonitor-Skript
des nuc (Skript #56646, Projekt `E:\Desktop\Smart Home\Eigenes`). Zwei
Gerätemodule über einer gemeinsamen Basisklasse (`libs/CellMonitorBase.php`).

## Architektur

- **Basisklasse** `CellMonitorBase` (abstrakt, `IPSModuleStrict`):
  ModBus-Framing (FC03/FC06) direkt auf dem Client Socket, **zwei Framings**:
  MBAP (Standard, Marstek) und **RTU über TCP** (CRC16; Kindklasse
  überschreibt `useRtuFraming()` — BYD!). MBAP nutzt Transaktions-ID-Matching
  im `RxBuffer` (Instanz-ID im High-Byte); RTU hat keine Transaktions-ID,
  dort sichern Bus-Semaphore + Puffer-Reset die Zuordnung. Semaphore **je Parent**
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
- BCU spricht auf **Port 8080 ModBus RTU ÜBER TCP** (CRC16, KEIN MBAP!),
  Unit-ID 1 — Fund 27.08.2026: erpes 0-Byte-Antwort auf das MBAP-Testskript,
  sein funktionierender Gateway-Modus „Modbus RTU over TCP", der identische
  Modus (GatewayMode 2) am produktiven nuc-Gateway und sarnaus
  `ModbusRtuFramer` bestätigen das übereinstimmend (HVM wie HVS).
- erpes HVS antwortet auf der Hotspot-IP 192.168.16.254 über eine statische
  Route (Fritzbox → LAN-IP der BCU); ob die LAN-IP selbst ModBus anbietet,
  ist offen (sein Test dorthin scheiterte, war aber auch MBAP).
- **HVS am 28.08.2026 an erpes Anlage verifiziert** (2 Türme à 4 Module,
  32 Zellen je Modul, 128 Zellen): Zell-Offset 48 gilt unverändert, die Zellen
  laufen dort bis Wort 175. **Die Temperaturzone ist größer als beim HVM** —
  Worte 177–200 belegt (24 Worte = 48 Sensoren = **12 je Modul**, HVM: 4 Worte
  = 8 Sensoren). Gegenprobe: Max/Min der Zone (27/24 °C) decken sich mit den
  Worten 6/7 des Statusblocks. Das Modul vermisst die Zone seit build 11 selbst
  (`tempWordsPerModule()`, gedeckelt auf `ceil(Zellen je Modul / 4)`), statt
  4 Worte je Modul anzunehmen.
- **Fensterlesungen: 4 genügen bei beiden Bauformen.** Die 5. quittiert der HVM
  mit leerer Antwort, der HVS mit ModBus-Ausnahme 4 — seit build 11 bricht die
  Schleife ab, sobald der Puffer Zellen und Temperaturzone trägt.
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
- **Wort 17/19 sind Energiezähler, keine Zyklen** (geklärt 28.08.2026, build 14).
  sarnaus Deutung „Charge Cycles / Discharge Cycles" (0x0511/0x0513) führt in die
  Irre: Die Werte wachsen streng monoton um ~80 Schritte/Tag. Geeicht gegen die
  AC-seitigen SBS-Zähler des nuc über 7/30/90 Tage — stabil **104,6 Wh je Schritt
  beim Laden, 95,0 Wh beim Entladen**. Die Abweichung von 100 Wh ist genau der
  Wandlerverlust (Round-Trip 90,6 %), DC-seitig ist ein Schritt also **0,1 kWh**.
  Das Modul führt sie seit build 14 als „Geladene/Entladene Energie (BMU)" in kWh.
  Äquivalente Vollzyklen ergeben sich daraus als Entladeenergie ÷ nutzbare Kapazität
  (nuc: 3.284 kWh ÷ 10,8 kWh ≈ 300 Zyklen bei SOH 91).
- **Zwei Ebenen — Box und Turm** (geklärt 28.08.2026 mit erpes Be-Connect-Screenshots):
  Der **Statusblock 0x0500** (ohne Handshake) liefert die Werte der **ganzen Box** —
  genau die „Information"-Seite von Be Connect Plus: SOC als Mittel (30 %), Strom als
  Summe beider Türme (−2,8 A = −1,5 + −1,2). Die **turmgenauen** Werte stehen im
  **Fensterblock hinter dem Handshake** und sind gegen Be Connect verifiziert:
  Wort **0/1** = Max/Min-Zellspannung (mV), **3/4** = Temperatur max/min, **20** =
  Batteriespannung, **23** = Ausgangsspannung (je ×0,1 V), **24** = SOC ×0,1,
  **25** = SOH. Beleg: Türme mit 33,0/28,3 % gegen Be Connect 32,6/27,7 %.
  Seit build 16 holt der Statuspoll diese Werte turmgenau, sobald die Box mehr als ein
  BMS meldet (Config Word); die Handshake-Logik steckt dafür in `readWindow()`.
- **Seriennummer beginnt bei Wort 33, nicht 34** (Korrektur 28.08.2026): erpes Nummer
  lautet `P030T020Z2308311111`, gelesen wurde `30T020Z…` — das führende Wort 33
  (0x5030 = „P0") fehlte. Rechts füllt die BMU mit „x" auf; das wird abgeschnitten.
- **`compatibility.date` gehört auf 0** und darf beim Build-Hochzählen nicht mit dem
  Bibliotheksdatum überschrieben werden — sonst verlangt Symcon einen Kernel, der
  mindestens so neu ist, und lehnt Installation wie Update ab.
- **Max/Min-Zellspannung im Statusblock hat nur 10-mV-Auflösung** (Wort 1/2 × 0,01 V).
  Bei engem Turm stehen dort Max = Min und Delta 0 — kein Fehler; die feinen Werte
  (1 mV) kommen aus der Zellmessung.
- **BMS-Index 2 (zweiter Turm) erprobt** (erpe, 28.08.2026): zwei Instanzen am
  selben Client Socket liefern beide sauber; die Bus-Semaphore hält sie
  auseinander.

## Marstek (am Gerät erprobt, 28.08.2026)

**Erster echter Lauf bestanden** (build 14, Instanz gegen die Venus E 3.0 in Neustadt):
`PollStatus` 2,3 s (viele Einzelregister — Bündeln wäre eine Optimierung), `Measure`
0,16 s. Alle Werte decken sich mit der unabhängigen Erfassung derselben Batterie —
SOC 40 vs. 40,4 %, 53,05 vs. 53,04 V, Zellen 3,318/3,314 vs. 3,316/3,313 V, Delta
4 vs. 5 mV, Temperaturen 30,6/28,4 vs. 30,7/28,5 °C, Innentemperatur 39,9 vs. 39,8 °C,
Zyklen 87 = 87. **Abweichung nur beim Batteriestrom** (6,9 A gegen 0,6 A): Aus
305–332 W bei 53 V folgen rund 6 A — das Modul liegt richtig, die dortige Erfassung
skaliert Register 30101 um den Faktor 10 zu klein.


**Port 5200, nicht 502** (Fund 28.08.2026 an der Venus E 3.0 in Neustadt,
192.168.10.187, gelesen über Tailscale): Auf 502 antwortet zwar ebenfalls ein
ModBus-Dienst, quittiert aber jedes Register der v3-Karte mit Ausnahme 2. Über
**5200** kamen alle unten genannten Register plausibel — SOC 21,7 %, 16 Zellen
3262–3266 mV, 52,21 V / −6,2 A, −339 W, 32,4 °C intern, Zelle max/min 27,4/25,8 °C,
Max/Min-Zelle 3265/3262 deckungsgleich mit den Einzelzellen. **Das Gerät nimmt
mehrere ModBus-Clients gleichzeitig an** — Neustadts eigene Anbindung (Socket
#58979 → .187:5200, Gateway #44245, Device #51383) lief währenddessen ungestört
weiter. Prüfskript: `venus_probe.php` (Scratchpad, reines MBAP-FC03).


Registerkarte nach ViperRNMC/marstek_venus_modbus (`registers/e_v3.yaml`,
Stand 08/2026), Venus E v3:
- 16 Zellen ab Register **34018** (mV), Min/Max-Zelle 37008/37007 (mV)
- SOC 34002 ×0,1; Zyklen 34003; Spannung 30100 ×0,01; Strom 30101 ×0,1 signed;
  Leistung 30001 signed; Temperaturen 35000/35010/35011 ×0,1
- Alarm 36000 (2 Register), Fault 36100 (4 Register) — Bedeutung der Bits
  noch nicht dokumentiert, werden als Hex-String ausgegeben
- Venus E **v3 spricht ModBus direkt über das Netz** (Port 5200, s. o.);
  v1/v2 brauchen eine RS485-Bridge (Elfin EW11, USR DR134) im ModBus-TCP-Modus
  (dort dann Port 502). **v1/v2 haben
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

## Kompatibilität (korrigiert 28.08.2026: 9.0)

`compatibility.version = 9.0` — bestimmt durch den **IO-Datenfluss**:
- **Seit Symcon 9.0 ist der Buffer im Socket-Datenfluss hex-kodiert**
  (`bin2hex`/`hex2bin`). Die utf8-Kodierung aus der (veralteten!) SDK-Doku wird
  vom Kernel als Hex-String fehlinterpretiert → Datenmüll auf der Leitung
  (Alpha-Befund 27./28.08.: gesendet `01 03 05 00 00 14 45 09`, auf der Leitung
  `00 00 00 0E`; per Testbox-Listener reproduziert und mit build 9 behoben).
  Referenz: WLED-Commit `de77200` (13.03.2026) — „Hex-Handling",
  „Library auf Symcon 9.0 angehoben". Burkhard hatte die 9.0-Korrektur richtig
  in Erinnerung.
- **9.1 ist NICHT nötig** — der RequestRead-Blockabfrage-Fix (t/143397) betrifft
  nur den Symcon-ModBus-Stack; das Modul framet selbst auf dem Client Socket.
- Nachrangig (wäre ohne den Datenfluss die Grenze gewesen): Darstellungen
  brauchen ≥ 8.0, typisierte Klassenkonstanten PHP ≥ 8.3 (ab 8.1 belegt).
- `VISU_PostNotification` ist per `function_exists` + `WFC_PushNotification`-
  Fallback abgesichert und daher nicht versionskritisch.

## Konventionen

Version/Build in `library.json` pflegen (Commit-Subject
`<version> build <NN>: <Beschreibung>`); Details in der globalen `CLAUDE.md`.
