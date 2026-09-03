# Battery Cell Monitor

[![Checks](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml)

Symcon-Modulbibliothek zur **Überwachung der einzelnen Zellspannungen** von
Heimspeicher-Batterien. Sichtbar werden Werte, die Wechselrichter und Portale
verschweigen: einzelne Zellen, die am Ladeschluss davonlaufen oder am
Entladeschluss einbrechen — die frühesten Anzeichen für Alterung oder Defekte.

| Modul | Gerät | Status |
|---|---|---|
| **BYD Battery Cell Monitor** | BYD Battery-Box Premium (BCU, ModBus RTU über TCP, Port 8080) | erprobt an HVM (5 Module à 16 Zellen) und HVS (2 Türme à 4 Module à 32 Zellen); Beta |
| **Marstek Battery Cell Monitor** | Marstek Venus E (v3 direkt über Netzwerk, Port 5200; ältere über RS485-Bridge) | an einer Venus E 3.0 erprobt (Werte gegen eine unabhängige Erfassung geprüft); Beta |

> **Beta-Stand:** Die Bibliothek ist neu und seit 08/2026 im **Module Store
> (Kanal Beta)** verfügbar; beide Module laufen an echter Hardware. Das
> BYD-Ausleseverfahren ist zuvor über Monate als Skript produktiv gelaufen.
> Rückmeldungen gern im
> [Symcon-Forum](https://community.symcon.de/t/144307).

## Funktionsumfang

- Alle Zellspannungen je Modul (Min/Max/Spannweite), Zell-Delta über den Turm
- Statuswerte: SOC, Strom, Batteriespannung, SOH (BYD), Temperaturen, Fehlerbits
- **Automatische Messung am Lade- und Entladeschluss** (SOC-Trigger mit Sperrzeit)
  plus zyklische Grundlinien-Messung
- **Schwellwert-Hinweise mit Push-Meldung** (Kachel-Visualisierung), je Stufe
  mit eigener Sperrzeit
- Neue Variablen werden auf Wunsch automatisch im Archiv protokolliert
- Rohdaten der letzten Messung optional als JSON (für eigene Auswertungen)

Die Module sprechen ModBus **direkt über einen Client Socket** — es werden
keine ModBus-Gateway-/Geräte-Instanzen benötigt, und der Blockabfrage-Fix aus
Symcon 9.1 ist nicht erforderlich. Das Framing ist je Gerät hinterlegt:
BYD nutzt ModBus RTU über TCP (CRC16), Marstek ModBus TCP (MBAP).

## Installation

Über den **Module Store** (Kanal Beta): dort heißt die Bibliothek
*Battery Cell Monitor* — Updates kommen dann automatisch.

Alternativ direkt aus Git, Kern-Konsole → **Modulverwaltung** → Hinzufügen:

```
https://github.com/bumaas/BatteryCellMonitor.git
```

## Einrichtung

1. Instanz **BYD Battery Cell Monitor** (bzw. Marstek) anlegen — der Client
   Socket wird automatisch miterstellt (oder über „Schnittstelle ändern" einen
   vorhandenen wählen).
2. Im Client Socket die IP des Geräts eintragen:
   - BYD: IP der BCU (Battery-Box), **Port 8080**. Die BCU muss dafür im
     Heimnetz hängen (LAN-Kabel an den RJ45-Port der BCU); die Hotspot-Adresse
     `192.168.16.254` ist nur aus dem BYD-eigenen WLAN bzw. über eine passende
     Route erreichbar.
   - Marstek Venus E v3: IP des Geräts, **Port 5200** (der WLAN-/LAN-ModBus des
     Geräts; auf Port 502 antwortet zwar auch ein ModBus-Dienst, dort sind die
     Register aber nicht erreichbar). Ältere Venus E über eine
     RS485-zu-Ethernet-Bridge im ModBus-TCP-Modus (z. B. Elfin EW11), dort Port 502.
     **Statusintervall auf höchstens 25 s stellen:** Die Venus trennt die Verbindung
     nach 30 s ohne Verkehr, und Symcon verbindet dann bei jeder Abfrage neu — das
     füllt das Logbuch mit „Wiederverbinden erfolgreich". Mit einem Intervall unter
     dem Timeout bleibt die Verbindung stehen.
3. Bei BYD den **BMS-Index** wählen (Turm 1, 2, …). Für einen zweiten Turm eine
   zweite Instanz **am selben Client Socket** anlegen (BMS-Index 2) — die
   Zugriffe werden automatisch serialisiert.
4. Optional: Visualisierung für Push-Meldungen wählen, Schwellwerte anpassen.
5. **„Zellen jetzt messen"** klicken — die Modul-Variablen entstehen bei der
   ersten Messung.

Wer die BCU bisher über ModBus-Gateway-/Geräte-Instanzen abfragt: diese vorher
**löschen oder inaktiv setzen**. Das Modul liefert die Statuswerte selbst, und
zwei Abnehmer am selben Socket stören sich gegenseitig.

## Konfiguration (Referenz)

| Einstellung | Vorgabe | Bedeutung |
|---|---|---|
| BMS-Index (nur BYD) | 1 | Turm-Nummer bei Mehrturm-Anlagen |
| Anzahl Module (nur BYD) | 0 | 0 = automatisch aus dem Config Word; bei HVS besser fest eintragen |
| Zellen je Modul (nur BYD) | 16 | HVM: 16, HVS: 32 |
| ModBus-Geräte-ID | 1 | Unit-ID im ModBus-Frame |
| Zeitlimit | 2000 ms | Antwort-Timeout je Anfrage |
| Status-Abfrageintervall | 60 s | Kurzabfrage (SOC, Strom, …); 0 = aus |
| Zellmess-Intervall | 60 min | zyklische Komplettmessung; 0 = aus |
| Messen ab / bis SOC | 99 % / 5 % | zusätzliche Messung am Lade-/Entladeschluss |
| Mindestabstand SOC-Messungen | 7200 s | Sperrzeit für die SOC-getriggerten Messungen |
| Schwellwerte | s. u. | Warn-/Kritisch-Grenzen in mV |
| Visualisierung für Push | 0 | Kachel-Visualisierung; 0 = keine Push-Meldungen |
| Abstand Warn-/Krit-Pushes | 24 h / 4 h | höchstens eine Meldung je Sperrzeit |
| Archiv-Protokollierung | an | neue numerische Variablen automatisch loggen |
| Rohdaten aufbewahren | aus | letzte Messung als JSON in einer String-Variable |

## Variablen

Statuswerte (je nach Gerät): Ladezustand, Batteriestrom (negativ = Laden),
Batteriespannung, SOH bzw. Leistung, Max./Min. Zellspannung, Zell-Delta,
Zelltemperaturen, Fehler-/Alarm-Bits sowie beim BYD die von der BMU gezählte
geladene und entladene Energie, die BMU-Temperatur und die Ausgangsspannung.

Beim BYD stehen ganz oben zwei Stammdaten der BCU: **BMU-Firmware** und
**BMS-Firmware** (etwa `V3.26` / `V3.31`). Das Modul liest sie beim ersten Lauf
und danach einmal am Tag — ein Firmware-Update der Batterie fällt so von selbst auf.

Je Zellmessung: pro Modul **Min/Max/Spannweite** (und Max-Temperatur), dazu
Turm-weit „Letzte Zellmessung", „SOC bei Messung", „Strom bei Messung" und
„Letzter Hinweis" (Text der letzten Schwellwert-Meldung).

## Bedienung per Skript

```php
BYDCM_Measure(12345);      // Komplettmessung aller Zellen
BYDCM_PollStatus(12345);   // Statusblock sofort abfragen
// Marstek entsprechend: MSTCM_Measure(...), MSTCM_PollStatus(...)
```

## Etwas Batterietheorie

Alle unterstützten Speicher arbeiten mit **LiFePO4-Zellen** (LFP). Deren
Kennlinie ist der Grund, warum es dieses Modul gibt: Zwischen etwa 10 und 90 %
Ladezustand liegt die Zellspannung nahezu unverändert bei 3,2–3,35 V. In diesem
Plateau sagt die Spannung fast nichts über den Zustand einer einzelnen Zelle
aus — erst an den Enden wird die Kurve steil, und dort zeigt sich, welche Zelle
noch mitkommt und welche nicht. Daraus folgen drei Dinge:

- **Gemessen wird an den Enden.** Deshalb misst das Modul zusätzlich zur
  zyklischen Messung, sobald der SOC den Ladeschluss (99 %) oder den
  Entladeschluss (5 %) erreicht. Eine Messung bei 60 % SOC zeigt fast immer ein
  sauberes Bild, auch wenn eine Zelle längst schwächelt.
- **Die Spannweite sagt mehr als der Absolutwert.** Eine Zelle, die am
  Ladeschluss deutlich über ihren Nachbarinnen liegt, ist eher voll — sie hat
  weniger Kapazität. Interessant ist daher, wie sich die Spannweite eines Moduls
  über Wochen und Monate entwickelt, nicht ihr Wert an einem einzelnen Tag.
  Genau dafür lohnt die Archiv-Protokollierung.
- **Balancing braucht Zeit am oberen Ende.** Das BMS gleicht passiv aus, mit
  Strömen im Bereich weniger Zehntel Ampere, und nur bei hohem Ladezustand. Ein
  Speicher, der monatelang zwischen 20 und 80 % pendelt, driftet langsam
  auseinander; eine gelegentliche Vollladung ist insofern Wartung.

Die Temperaturen sind das zweite Frühwarnsignal: Zellen desselben Moduls stehen
im gleichen Gehäuse und sollten sich um wenige Kelvin unterscheiden. Eine
dauerhaft größere Spreizung deutet auf ungleiche Kühlung oder einen schlechten
Kontakt — und ein warm laufender Übergangswiderstand fällt auf, lange bevor die
Spannungen auffällig werden.

## Schwellwerte (Vorgaben)

Die Grenzen sind **Vorgaben des Moduls, keine Herstellerwerte** — sie werden
nicht aus dem BMS gelesen, sondern sind LiFePO4-typische Erfahrungswerte und in
jeder Instanz frei einstellbar.

| Schwelle | Vorgabe | Gedanke dahinter |
|---|---|---|
| Warnung hoch | 3570 mV | oberes Ende des normalen Ladeschlusses; ab hier wird die Kennlinie steil |
| Kritisch hoch | 3650 mV | Ladeschlussspannung der Zelle — hier greift der Zellschutz |
| Warnung niedrig | 2850 mV | unteres Knie der Kennlinie, deutlich vor der Abschaltung |
| Kritisch niedrig | 2600 mV | Tiefentladebereich |
| Spannweite je Modul | 300 mV | großzügig, weil am Ladeschluss gemessen wird |
| Temperaturspreizung | 8 K | 2–5 K sind im Betrieb üblich |

Das BMS schützt die Zellen selbst; die Hinweise dienen der **Früherkennung**,
nicht dem Schutz. Deshalb kann das Modul warnen, während die App oder das Portal
des Herstellers schweigt: Dort schlägt erst die eigene Schutzschwelle an, die
höher liegt.

### Am Lade- und Entladeschluss ruhen die Absolutwarnungen

Genau dort, wo das Modul misst, ist die absolute Zellspannung kein Merkmal der
Zelle mehr, sondern des Betriebspunkts: Am steilen Ende der Kennlinie hebt schon
der Innenwiderstand die Klemmenspannung, und wie weit, hängt am Ladestrom des
Augenblicks — bei kräftiger Sonne läuft dieselbe gesunde Zelle höher als bei
schwacher. Eine Warnung darauf käme bei jeder Vollladung erneut und würde
dadurch zum Rauschen, ohne je etwas über den Zustand der Zelle zu sagen.

Deshalb prüft das Modul die beiden **Warn**schwellen auf den Absolutwert nur
außerhalb der Enden. Als Marke dienen dieselben SOC-Grenzen, die die
automatische Messung auslösen (Vorgabe: ab 99 % bzw. bis 5 %; eine auf 0
gesetzte Grenze schaltet die Ausnahme auf ihrer Seite ab). Unberührt bleiben:

- die **Spannweite je Modul** — am Kennlinienende sogar die eigentliche Kennzahl,
- die **kritischen Schwellen**, die die Schutzgrenzen der Zelle markieren und
  auch am Ladeschluss nicht erreicht werden sollten,
- die **Temperaturspreizung**.

Das Debug-Fenster weist eine solche Unterdrückung aus („Kennlinienende bei SOC
99 %: 3 Absolutwert-Warnung(en) unterdrückt"), damit nachvollziehbar bleibt,
warum es still war.

### Worauf man stattdessen schaut

Ein einzelner Messwert taugt nicht zur Beurteilung — zwei Verlaufsfragen dagegen
schon, und beide beantworten die protokollierten Variablen:

- **Bleibt dieselbe Zelle oben?** `CellMaxNumber` nennt die Nummer der höchsten
  Zelle. Wandert sie von Messung zu Messung, ist das Entwarnung: Dann liegt
  keine Zelle dauerhaft vorn. Zeigt sie über Wochen auf dieselbe Nummer, lohnt
  der genaue Blick auf deren Modul.
- **Wächst die Spannweite?** Nicht ihr Wert an einem Tag zählt, sondern der
  Trend über Monate bei vergleichbarem SOC. Eine Zelle, die Kapazität verliert,
  läuft am Ladeschluss immer früher und immer weiter davon.

Zur Einordnung die Werte einer gesunden Anlage (HVM, 5 Module, 60 Tage): Die
höchste Zelle wechselte über 75 Messungen zwischen acht verschiedenen Nummern,
die Modulspannweite schwankte tagesweise zwischen 157 und 271 mV ohne jede
Richtung, und einzelne Zellen erreichten am Ladeschluss bis zu 3646 mV.

## Fehlersuche

- **Keine Werte / Timeout:** Debug-Fenster der Instanz öffnen. „Timeout - keine
  Antwort vom Gerät" bei offenem Socket deutet auf das falsche Ziel (BCU nicht
  im Heimnetz) oder einen zweiten Abnehmer am selben Socket.
- **„BMS-Index … ist ungültig":** Die BMU kennt den gewählten Turm nicht
  (STATUS-Antwort `0x4000`) — Index prüfen.
- **Max. = Min. Zellspannung, Zell-Delta 0 mV:** kein Fehler. Diese beiden Werte
  stammen aus dem Statusblock und haben dort nur 10-mV-Auflösung; bei einem engen
  Turm fallen sie zusammen. Die feinen Werte je Modul entstehen bei einer Zellmessung.
- **Warnung, obwohl die Hersteller-App nichts meldet:** erwartbar. Die Vorgaben
  liegen bewusst unter den Schutzschwellen des BMS (siehe „Schwellwerte").
- **Hohe Zellspannung, aber keine Meldung:** kein Fehler, sondern Absicht. Am
  Lade- und Entladeschluss ruhen die Warnungen auf den Absolutwert, weil er dort
  vom Ladestrom bestimmt wird und nichts über die Zelle aussagt; geprüft werden
  weiterhin Spannweite und Schutzgrenzen (siehe „Schwellwerte"). Das
  Debug-Fenster nennt die Zahl der unterdrückten Warnungen.
- **Statuswerte bei mehreren Türmen identisch:** richtig so. SOC, SOH, Strom und die
  Energiezähler kommen aus dem BMU-Statusblock und gelten für die ganze Battery-Box;
  turmspezifisch sind nur die Werte aus der Zellmessung.
- **Keine „Ladezyklen":** Die Register, die dafür gehalten werden, sind in Wirklichkeit
  Energiezähler (0,1 kWh je Schritt) — das Modul führt sie als „Geladene/Entladene
  Energie (BMU)". Äquivalente Vollzyklen ergeben sich daraus als entladene Energie
  geteilt durch die nutzbare Kapazität.
- **Unplausible Zellwerte** (nicht um 3300 mV): Beim BYD die Debug-Zeile
  **„RawWords"** einer Messung sichern und im Forum posten — daraus lassen sich
  die Wort-Offsets abweichender Varianten (z. B. HVS) bestimmen.
- **Kein Push:** In der gewählten Visualisierung muss das eigene Gerät
  registriert sein; Testknopf „Testmeldung senden" nutzen. Die neue Symcon-App
  registriert sich an der Kachel-Visualisierung, nicht am alten WebFront.

## Protokoll-Hintergrund

- BYD: Handshake-Verfahren mit wanderndem 65-Register-Fenster nach
  [sarnau/BYD-Battery-Box-Infos](https://github.com/sarnau/BYD-Battery-Box-Infos);
  die BMU akzeptiert nur einzelne FC06-Writes (kein FC16), Framing ist
  ModBus RTU über TCP.
- Marstek: Registerkarte nach
  [ViperRNMC/marstek_venus_modbus](https://github.com/ViperRNMC/marstek_venus_modbus)
  (Venus E v3: Zellspannungen ab Register 34018).

## Voraussetzungen

- Symcon ab Version 9.0 (der IO-Datenfluss ist seit 9.0 hex-kodiert)
- Batterie im gleichen Netz erreichbar (keine Portfreigaben ins Internet!)
