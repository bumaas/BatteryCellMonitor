# Battery Cell Monitor

[![Checks](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml)

Symcon-Modulbibliothek zur **Überwachung der einzelnen Zellspannungen** von
Heimspeicher-Batterien. Sichtbar werden Werte, die Wechselrichter und Portale
verschweigen: einzelne Zellen, die am Ladeschluss davonlaufen oder am
Entladeschluss einbrechen — die frühesten Anzeichen für Alterung oder Defekte.

| Modul | Gerät | Status |
|---|---|---|
| **BYD Battery Cell Monitor** | BYD Battery-Box Premium (BCU, ModBus RTU über TCP, Port 8080) | erprobt an HVM (5 Module à 16 Zellen) und HVS (2 Türme à 4 Module à 32 Zellen); Alpha |
| **Marstek Battery Cell Monitor** | Marstek Venus E (v3 direkt über Netzwerk, Port 5200; ältere über RS485-Bridge) | Registerkarte an einer Venus E 3.0 verifiziert, Modul selbst noch ungetestet |

> **Alpha-Stand:** Die Bibliothek ist neu. Das BYD-Ausleseverfahren läuft seit
> 08/2026 produktiv (als Skript), das Modul selbst wird gerade an echter
> Hardware erprobt. Rückmeldungen gern im
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

Kern-Konsole → **Modulverwaltung** → Hinzufügen:

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
     RS485-zu-Ethernet-Bridge im ModBus-TCP-Modus (z. B. Elfin EW11), dort Port 502
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
Zelltemperaturen, Fehler-/Alarm-Bits, Ladezyklen.

Je Zellmessung: pro Modul **Min/Max/Spannweite** (und Max-Temperatur), dazu
Turm-weit „Letzte Zellmessung", „SOC bei Messung", „Strom bei Messung" und
„Letzter Hinweis" (Text der letzten Schwellwert-Meldung).

## Bedienung per Skript

```php
BYDCM_Measure(12345);      // Komplettmessung aller Zellen
BYDCM_PollStatus(12345);   // Statusblock sofort abfragen
// Marstek entsprechend: MSTCM_Measure(...), MSTCM_PollStatus(...)
```

## Schwellwerte (Vorgaben)

LiFePO4-typische Werte, in mV je Zelle: Warnung ab 3570 / kritisch ab 3650
(Ladeschluss), Warnung unter 2850 / kritisch unter 2600 (Entladeschluss),
Modul-Spannweite ab 250. Die BMU/das BMS schützt die Zellen selbst — die
Hinweise dienen der Früherkennung, nicht dem Schutz. Wer täglich an der
Warnschwelle kratzt (alternde Zellen), hebt sie leicht an, statt die Meldungen
zu ignorieren.

## Fehlersuche

- **Keine Werte / Timeout:** Debug-Fenster der Instanz öffnen. „Timeout - keine
  Antwort vom Gerät" bei offenem Socket deutet auf das falsche Ziel (BCU nicht
  im Heimnetz) oder einen zweiten Abnehmer am selben Socket.
- **„BMS-Index … ist ungültig":** Die BMU kennt den gewählten Turm nicht
  (STATUS-Antwort `0x4000`) — Index prüfen.
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
