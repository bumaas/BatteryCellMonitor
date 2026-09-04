# Fixtures

Rohdaten echter Anlagen, an denen die Laufzeit-Checks rechnen. Jede Datei trägt im Feld
`herkunft`, woher der Mitschnitt stammt; `worte` sind die ModBus-Register als Dezimalzahlen
in Leserichtung.

| Datei | Anlage | Inhalt |
|---|---|---|
| `statusblock_hvm.json` | BYD HVM, fünf Module, ein Turm (Anlage des Autors) | Antwort auf FC03 ab Register 0x0500, 20 Worte |
| `window_hvs_tower1.json` | BYD HVS, zwei Türme (erpe) | erste 26 Worte des Fensterblocks, Turm 1 |
| `window_hvs_tower2.json` | BYD HVS, zwei Türme (erpe) | erste 26 Worte des Fensterblocks, Turm 2 |

Zwei Regeln beim Ergänzen:

- **Keine erfundenen Werte.** Eine nachgebaute Fixture bestätigt nur die eigene Annahme; die
  Skalierungen und Sonderfälle der BMU (10-mV-Raster im Statusblock, `0xFFFF` als „noch kein
  Wert", vorzeichenbehaftete Temperaturen) fallen dabei unter den Tisch.
- **Vor dem Ablegen auf private Gerätedaten prüfen.** Der Fensterblock führt ab Wort 33 die
  Seriennummer der Batterie im Klartext — die Fixtures hier sind deshalb auf die 26
  Statusworte gekürzt.
