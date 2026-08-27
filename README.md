# Battery Cell Monitor

[![Checks](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml)

Symcon-Modulbibliothek zur **Überwachung der einzelnen Zellspannungen** von
Heimspeicher-Batterien. Sichtbar werden Werte, die Wechselrichter und Portale
verschweigen: einzelne Zellen, die am Ladeschluss davonlaufen oder am
Entladeschluss einbrechen — die frühesten Anzeichen für Alterung oder Defekte.

| Modul | Gerät | Status |
|---|---|---|
| **BYD Battery Cell Monitor** | BYD Battery-Box Premium (BCU, ModBus RTU über TCP, Port 8080) | Protokoll erprobt (HVM, 5 Module à 16 Zellen) |
| **Marstek Battery Cell Monitor** | Marstek Venus E (v3 direkt per Ethernet, ältere über RS485-Bridge) | vorbereitet, **ungetestet** |

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

## Einrichtung

1. Instanz **BYD Battery Cell Monitor** (bzw. Marstek) anlegen — der Client
   Socket wird automatisch miterstellt.
2. Im Client Socket die IP des Geräts eintragen:
   - BYD: IP der BCU (Battery-Box), **Port 8080**
   - Marstek Venus E v3: IP des Geräts, **Port 502**; ältere Venus E über eine
     RS485-zu-Ethernet-Bridge im ModBus-TCP-Modus (z. B. Elfin EW11)
3. Bei BYD den **BMS-Index** wählen (Turm 1, 2, …); Modulzahl wird automatisch
   aus dem Config Word erkannt, kann aber fest vorgegeben werden.
4. Optional: Visualisierung für Push-Meldungen wählen, Schwellwerte anpassen.

## Schwellwerte (Vorgaben)

LiFePO4-typische Werte, in mV je Zelle: Warnung ab 3570 / kritisch ab 3650
(Ladeschluss), Warnung unter 2850 / kritisch unter 2600 (Entladeschluss),
Modul-Spannweite ab 250. Die BMU/das BMS schützt die Zellen selbst — die
Hinweise dienen der Früherkennung, nicht dem Schutz.

## Protokoll-Hintergrund

- BYD: Handshake-Verfahren mit wanderndem 65-Register-Fenster nach
  [sarnau/BYD-Battery-Box-Infos](https://github.com/sarnau/BYD-Battery-Box-Infos);
  die BMU akzeptiert nur einzelne FC06-Writes (kein FC16).
- Marstek: Registerkarte nach
  [ViperRNMC/marstek_venus_modbus](https://github.com/ViperRNMC/marstek_venus_modbus)
  (Venus E v3: Zellspannungen ab Register 34018).

## Voraussetzungen

- Symcon ab Version 8.1
- Batterie im gleichen Netz erreichbar (keine Portfreigaben ins Internet!)
