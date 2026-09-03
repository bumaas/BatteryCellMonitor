<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/CellMonitorBase.php';

/*
 * BYD Battery-Box Premium (HVM, erprobt an 5 Modulen à 16 Zellen) — liest alle
 * Zellspannungen und den Statusblock direkt von der BCU.
 *
 * Die BCU spricht auf Port 8080 ModBus RTU ÜBER TCP (CRC16, kein MBAP-Header) —
 * verifiziert per sarnau-Referenz (ModbusRtuFramer) und den produktiven
 * Symcon-Gateways im Modus "Modbus RTU over TCP" (HVM und HVS, 08/2026).
 *
 * Protokoll nach sarnau/BYD-Battery-Box-Infos, verifiziert 25.08.2026:
 * 1. Handshake: INDEX (0x0550, FC06) = BMS-Nummer, CMD (0x0551, FC06) = 0x8100,
 *    dann STATUS (0x0551, FC03) pollen bis 0x8801 (0x4000 = ungültiger Index).
 * 2. Viermal denselben 65-Register-Block ab 0x0558 lesen — das Fenster wandert
 *    von selbst; das erste Wort jeder Lesung ist ein Header und wird verworfen.
 * 3. Im zusammengesetzten Puffer: Zellspannung [mV] = Wort 48 + Modul*Zellen + Zelle,
 *    Modultemperaturen ab Wort 177 (2 Sensoren je Wort), Seriennummer Wort 34-45.
 *
 * Die BMU lehnt kombinierte FC16-Writes ab — es müssen einzelne FC06 sein.
 */
class BYDCellMonitor extends CellMonitorBase
{
    private const string PROP_BMSINDEX       = 'BMSIndex';
    private const string PROP_MODULECOUNT    = 'ModuleCount';    // 0 = automatisch aus dem Config Word
    private const string PROP_CELLSPERMODULE = 'CellsPerModule'; // HVM: 16

    private const string ATTR_SERIAL          = 'SerialNumber';
    private const string ATTR_DETECTEDMODULES = 'DetectedModules';
    private const string ATTR_DETECTEDBMS     = 'DetectedBMS';

    private const int REG_INDEX       = 0x0550; // 1360
    private const int REG_CMD         = 0x0551; // 1361 (FC06)
    private const int REG_STATUS      = 0x0551; // 1361 (FC03)
    private const int REG_WINDOW      = 0x0558; // 1368
    private const int REG_STATUSBLOCK = 0x0500; // 1280: SOC, Zellspannungen min/max, SOH, Strom, ...
    private const int REG_INFOBLOCK   = 12;     // 12-17: BMU-Version, ?, BMS-Version, -, Config Word, Batterietyp
    private const int INFOBLOCK_SIZE  = 6;

    private const int CMD_START        = 0x8100;
    private const int STATUS_READY     = 0x8801;
    private const int STATUS_BAD_INDEX = 0x4000;

    private const int WINDOW_READS   = 5;   // Obergrenze; abgebrochen wird, sobald der Puffer reicht (HVM wie HVS: 4 Lesungen)
    private const int WINDOW_SIZE    = 65;  // 1 Header-Wort + 64 Datenworte
    private const int OFFSET_SERIAL  = 33;  // Worte 33-44 (12 Worte à 2 ASCII-Zeichen); 28.08.2026
                                            // gegen Be Connect Plus korrigiert - vorher fehlten
                                            // die ersten beiden Zeichen ("P0…").
    private const int OFFSET_CELLS   = 48;
    private const int OFFSET_TEMPS   = 177; // 2 Sensoren je Wort; Wortzahl je Modul variiert (HVM 4, HVS 6)
    private const int TEMPWORDS_MIN_PER_MODULE = 4;
    private const int TEMP_PLAUSIBEL_MAX       = 80;    // °C je Sensorbyte - trennt Temperaturen von Folgedaten

    protected function useRtuFraming(): bool
    {
        return true;
    }

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyInteger(self::PROP_BMSINDEX, 1);
        $this->RegisterPropertyInteger(self::PROP_MODULECOUNT, 0);
        $this->RegisterPropertyInteger(self::PROP_CELLSPERMODULE, 16);
        $this->RegisterAttributeString(self::ATTR_SERIAL, '');
        $this->RegisterAttributeInteger(self::ATTR_DETECTEDMODULES, 0);
        $this->RegisterAttributeInteger(self::ATTR_DETECTEDBMS, 0);
    }

    protected function registerVendorVariables(): void
    {
        // Stammdaten der BCU (Position 1-9); sie stehen im Infoblock und ändern sich nur
        // bei einem Firmware-Update.
        $this->ensureVariable('BMUVersion', $this->Translate('BMU firmware'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 1);
        $this->ensureVariable('BMSVersion', $this->Translate('BMS firmware'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 2);
        $this->ensureVariable('BatteryVoltage', $this->Translate('Battery voltage'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 1,
        ], 12);
        $this->ensureVariable('SOH', $this->Translate('State of health'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0,
        ], 13);
        $this->ensureVariable('InternalTemp', $this->Translate('BMU temperature'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 0,
        ], 14);
        $this->ensureVariable('OutputVoltage', $this->Translate('Output voltage'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 1,
        ], 15);
        $this->ensureVariable('CellTempMax', $this->Translate('Max cell temperature'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 0,
        ], 23);
        $this->ensureVariable('CellTempMin', $this->Translate('Min cell temperature'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 0,
        ], 24);
        $this->ensureVariable('ErrorBitmask', $this->Translate('Error bitmask'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'DIGITS' => 0,
        ], 25);
        // Beides sind seit Werk monoton steigende Zähler - im Archiv entsprechend aggregieren.
        $this->ensureVariable('EnergyCharged', $this->Translate('Charged energy (BMU)'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' kWh', 'DIGITS' => 1,
        ], 26, true);
        $this->ensureVariable('EnergyDischarged', $this->Translate('Discharged energy (BMU)'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' kWh', 'DIGITS' => 1,
        ], 27, true);
    }

    /** Statusblock ab 0x0500 lesen (dauerhaft verfügbar, kein Handshake nötig). */
    protected function readStatusValues(): ?int
    {
        $words = $this->withBusLock(fn(): ?array => $this->readHoldingRegisters(self::REG_STATUSBLOCK, 20));
        if (!is_array($words)) {
            return null;
        }

        $soc = $words[0];
        if ($soc > 100) { // 0xFFFF u. ä.: BMU liefert (noch) keinen gültigen Wert
            $this->SendDebug(__FUNCTION__, 'Ungültiger SOC-Rohwert: ' . $soc, 0);
            return null;
        }
        // Dieser Block gilt für die ganze Battery-Box (in Be Connect Plus die Seite
        // "Information": SOC als Mittel, Strom als Summe aller Türme). Bei mehreren Türmen
        // werden SOC, SOH, Zellspannungen und Temperaturen unten turmgenau überschrieben.
        $this->SetValue('SOC', $soc);
        $this->SetValue('CellVoltageMax', $words[1] / 100);
        $this->SetValue('CellVoltageMin', $words[2] / 100);
        $this->SetValue('CellDelta', ($words[1] - $words[2]) * 10);
        $this->SetValue('SOH', $words[3]);
        $this->SetValue('Current', self::toSigned16($words[4]) / 10);
        $this->SetValue('BatteryVoltage', $words[5] / 100);
        $this->SetValue('CellTempMax', self::toSigned16($words[6]));
        $this->SetValue('CellTempMin', self::toSigned16($words[7]));
        // Wort 8 = Reg. 1288 (BMU-Elektronik, nicht die Zellen), Wort 16 = Reg. 1296.
        // Skalierung wie in der abgelösten Blockabfrage: Temperatur roh, Spannung x0,01 V.
        $this->SetValue('InternalTemp', self::toSigned16($words[8]));
        $this->SetValue('OutputVoltage', $words[16] / 100);
        $this->SetValue('ErrorBitmask', $words[13]);

        // Wort 17/19 gelten in der Literatur als Lade- bzw. Entladezyklen, sind aber
        // Energiezähler in 0,1-kWh-Schritten (28.08.2026 am HVM gegen die AC-seitigen
        // Zähler geeicht: 104,6 Wh je Schritt beim Laden, 95,0 Wh beim Entladen - die
        // Differenz ist exakt der Wandlerverlust, DC-seitig also 100 Wh).
        $this->SetValue('EnergyCharged', $words[17] / 10);
        $this->SetValue('EnergyDischarged', $words[19] / 10);

        if ($words[13] !== 0) {
            $this->LogMessage(sprintf('BMU meldet Fehler-Bitmask 0x%X', $words[13]), KL_ERROR);
        }

        // Stammdaten beim ersten Lauf holen und danach täglich auffrischen - so taucht auch
        // ein Firmware-Update der BCU von selbst in den Variablen auf.
        if ($this->ReadAttributeInteger(self::ATTR_DETECTEDMODULES) === 0 || $this->stammdatenVeraltet()) {
            $this->detectConfiguration();
        }

        // Mehrturm-Anlage: die Werte dieses Turms holen (Be Connect Plus zeigt sie nach dem
        // Umschalten auf BMS1/BMS2 - sie stehen im Fensterblock hinter dem Handshake).
        if ($this->bmsCount() > 1) {
            $eigener = $this->readTowerStatus();
            if ($eigener !== null) {
                $soc = $eigener;
            }
        }
        return $soc;
    }

    /**
     * Statuswerte des eigenen Turms aus dem Fensterblock (eine Lesung genügt).
     * Offsets am 28.08.2026 gegen Be Connect Plus verifiziert (zwei HVS-Türme):
     * 0/1 = Max/Min-Zellspannung mV, 3/4 = Temperatur max/min, 20 = Batteriespannung,
     * 23 = Ausgangsspannung (beide x 0,1 V), 24 = SOC x 0,1, 25 = SOH.
     */
    private function readTowerStatus(): ?int
    {
        $buffer = $this->readWindow(26);
        if (!is_array($buffer) || count($buffer) < 26) {
            return null;
        }
        $soc = (int) round($buffer[24] / 10);
        if ($soc < 0 || $soc > 100) {
            $this->SendDebug(__FUNCTION__, 'Turm-SOC unplausibel: ' . $buffer[24], 0);
            return null;
        }
        $this->SetValue('SOC', $soc);
        $this->SetValue('SOH', $buffer[25]);
        $this->SetValue('CellVoltageMax', $buffer[0] / 1000);
        $this->SetValue('CellVoltageMin', $buffer[1] / 1000);
        $this->SetValue('CellDelta', $buffer[0] - $buffer[1]);
        $this->SetValue('CellTempMax', self::toSigned16($buffer[3]));
        $this->SetValue('CellTempMin', self::toSigned16($buffer[4]));
        $this->SetValue('BatteryVoltage', $buffer[20] / 10);
        $this->SendDebug(__FUNCTION__, sprintf(
            'Turm %d: SOC %d %%, SOH %d, Zellen %d-%d mV, %.1f V',
            $this->ReadPropertyInteger(self::PROP_BMSINDEX),
            $soc,
            $buffer[25],
            $buffer[1],
            $buffer[0],
            $buffer[20] / 10
        ), 0);
        return $soc;
    }

    /** BMS-Anzahl der Box (Config Word), Vorgabe 1. */
    private function bmsCount(): int
    {
        $erkannt = $this->ReadAttributeInteger(self::ATTR_DETECTEDBMS);
        return $erkannt > 0 ? $erkannt : 1;
    }

    /**
     * Handshake auf den eigenen BMS-Index, dann das wandernde Fenster lesen, bis mindestens
     * $wunsch Worte beisammen sind. Für die Statuswerte genügen 26 Worte (eine Lesung),
     * für die Zellmessung braucht es den ganzen Puffer.
     */
    private function readWindow(int $wunsch): ?array
    {
        return $this->withBusLock(function () use ($wunsch): ?array {
            if (!$this->writeSingleRegister(self::REG_INDEX, $this->ReadPropertyInteger(self::PROP_BMSINDEX))) {
                return null;
            }
            if (!$this->writeSingleRegister(self::REG_CMD, self::CMD_START)) {
                return null;
            }
            $ready = false;
            for ($i = 0; $i < 40; $i++) {
                IPS_Sleep(100);
                $status = $this->readHoldingRegisters(self::REG_STATUS, 1);
                if ($status === null) {
                    continue;
                }
                if ($status[0] === self::STATUS_READY) {
                    $ready = true;
                    break;
                }
                if ($status[0] === self::STATUS_BAD_INDEX) {
                    $this->LogMessage('BMS-Index ' . $this->ReadPropertyInteger(self::PROP_BMSINDEX) . ' ist ungültig', KL_ERROR);
                    return null;
                }
            }
            if (!$ready) {
                $this->LogMessage('Handshake-Timeout - Abfrage abgebrochen', KL_WARNING);
                return null;
            }

            $words = [];
            for ($read = 0; $read < self::WINDOW_READS; $read++) {
                $block = $this->readHoldingRegisters(self::REG_WINDOW, self::WINDOW_SIZE);
                if ($block === null) {
                    // spätere Lesungen dürfen scheitern, wenn der Puffer schon reicht
                    break;
                }
                array_shift($block); // Header-Wort jeder Lesung verwerfen
                $words = array_merge($words, $block);
                if (count($words) >= $wunsch) {
                    break;
                }
                IPS_Sleep(50);
            }
            return $words;
        });
    }

    /** Handshake + wanderndes Fenster: alle Zellspannungen lesen. */
    protected function readCellVoltages(): ?array
    {
        // Soviel Puffer, dass Zellen und Temperaturzone sicher enthalten sind; mehr Lesungen
        // quittiert die BMU je nach Turmgröße mit einer Ausnahme (HVM leere Antwort, HVS Code 4).
        $buffer = $this->readWindow(self::OFFSET_TEMPS + $this->moduleCount() * max(
            self::TEMPWORDS_MIN_PER_MODULE,
            (int) ceil($this->ReadPropertyInteger(self::PROP_CELLSPERMODULE) / 4)
        ));
        if (!is_array($buffer)) {
            return null;
        }
        $benoetigt = self::OFFSET_CELLS + $this->moduleCount() * $this->ReadPropertyInteger(self::PROP_CELLSPERMODULE);
        if (count($buffer) < $benoetigt) {
            $this->LogMessage(sprintf('Unvollständiger Messpuffer (%d von %d Worten) - Zellmessung verworfen', count($buffer), $benoetigt), KL_WARNING);
            return null;
        }

        $this->SetBuffer('LastRawWords', json_encode($buffer));
        // Rohworte im Debug ausgeben - wichtig zur Offset-Bestimmung bei noch unverifizierten Varianten (HVS)
        $this->SendDebug('RawWords', implode(' ', $buffer), 0);
        $this->extractSerialNumber($buffer);

        $moduleCount    = $this->moduleCount();
        $cellsPerModule = $this->ReadPropertyInteger(self::PROP_CELLSPERMODULE);
        $result         = [];
        for ($m = 0; $m < $moduleCount; $m++) {
            $result[$m + 1] = array_slice($buffer, self::OFFSET_CELLS + $m * $cellsPerModule, $cellsPerModule);
        }
        return $result;
    }

    protected function readModuleTemperatures(): array
    {
        $buffer = json_decode($this->GetBuffer('LastRawWords') ?: '[]', true);
        if (!is_array($buffer) || count($buffer) < self::OFFSET_TEMPS) {
            return [];
        }
        $moduleCount = $this->moduleCount();
        $proModul    = $this->tempWordsPerModule($buffer, $moduleCount);
        $temps       = [];
        for ($m = 0; $m < $moduleCount; $m++) {
            $sensoren = [];
            foreach (array_slice($buffer, self::OFFSET_TEMPS + $m * $proModul, $proModul) as $word) {
                foreach ([$word >> 8, $word & 0xFF] as $wert) {
                    // 0 heißt "kein Sensor an dieser Stelle" und darf das Minimum nicht verfälschen
                    if ($wert > 0 && $wert <= self::TEMP_PLAUSIBEL_MAX) {
                        $sensoren[] = $wert;
                    }
                }
            }
            if ($sensoren !== []) {
                $temps[$m + 1] = ['max' => max($sensoren), 'min' => min($sensoren)];
            }
        }
        return $temps;
    }

    /**
     * Wie viele Worte je Modul die Temperaturzone belegt - beim HVM 4 (8 Sensoren), beim HVS 6.
     * Statt einer festen Annahme wird die tatsächlich belegte Zone vermessen: ab OFFSET_TEMPS
     * zählen nur Worte, deren beide Sensorbytes eine plausible Temperatur tragen.
     */
    private function tempWordsPerModule(array $buffer, int $moduleCount): int
    {
        if ($moduleCount < 1) {
            return self::TEMPWORDS_MIN_PER_MODULE;
        }
        $belegt = 0;
        for ($i = self::OFFSET_TEMPS; $i < count($buffer); $i++) {
            $word = (int) $buffer[$i];
            if ($word === 0 || ($word >> 8) > self::TEMP_PLAUSIBEL_MAX || ($word & 0xFF) > self::TEMP_PLAUSIBEL_MAX) {
                break;
            }
            $belegt++;
        }
        // Deckel: ein Modul hat höchstens halb so viele Sensoren wie Zellen (HVM 8, HVS 12)
        $deckel = max(
            self::TEMPWORDS_MIN_PER_MODULE,
            (int) ceil($this->ReadPropertyInteger(self::PROP_CELLSPERMODULE) / 4)
        );
        return min($deckel, max(self::TEMPWORDS_MIN_PER_MODULE, (int) ceil($belegt / $moduleCount)));
    }

    private function moduleCount(): int
    {
        $configured = $this->ReadPropertyInteger(self::PROP_MODULECOUNT);
        if ($configured > 0) {
            return $configured;
        }
        $detected = $this->ReadAttributeInteger(self::ATTR_DETECTEDMODULES);
        return $detected > 0 ? $detected : 5;
    }

    /** Stammdaten noch nie oder länger als einen Tag nicht gelesen? */
    private function stammdatenVeraltet(): bool
    {
        $id = @$this->GetIDForIdent('BMUVersion');
        if ($id === false) {
            return true;
        }
        return IPS_GetVariable($id)['VariableUpdated'] < time() - 86400;
    }

    /**
     * Infoblock (Register 12-17) auswerten: Firmware-Versionen, Config Word (Bits 0-3 =
     * Modulzahl, 4-7 = BMS-Anzahl) und Batterietyp. Register 13 trägt eine dritte
     * Versionsangabe, deren Bedeutung offen ist (am HVM V3.22) - sie bleibt ungenutzt.
     */
    private function detectConfiguration(): void
    {
        $words = $this->withBusLock(
            fn(): ?array => $this->readHoldingRegisters(self::REG_INFOBLOCK, self::INFOBLOCK_SIZE)
        );
        if (!is_array($words)) {
            return;
        }
        $this->SetValue('BMUVersion', self::formatVersion($words[0]));
        $this->SetValue('BMSVersion', self::formatVersion($words[2]));

        $modules = $words[4] & 0x0F;
        $bms     = ($words[4] >> 4) & 0x0F;
        if ($bms > 0) {
            $this->WriteAttributeInteger(self::ATTR_DETECTEDBMS, $bms);
        }
        if ($modules > 0) {
            $this->WriteAttributeInteger(self::ATTR_DETECTEDMODULES, $modules);
        }
        $this->SendDebug(__FUNCTION__, sprintf(
            'Erkannt: %d Module, %d BMS, Batterietyp 0x%02X, BMU %s, BMS %s',
            $modules,
            $bms,
            $words[5] & 0xFF,
            self::formatVersion($words[0]),
            self::formatVersion($words[2])
        ), 0);
    }

    /** Versionswort der BCU: High-Byte = Haupt-, Low-Byte = Nebenversion (0x031A = V3.26). */
    private static function formatVersion(int $word): string
    {
        return sprintf('V%d.%d', $word >> 8, $word & 0xFF);
    }

    /**
     * Seriennummer aus dem Messpuffer lesen. Sie wird bei jeder Messung neu bestimmt und nur
     * bei Abweichung geschrieben - so übernimmt eine bestehende Instanz auch eine korrigierte
     * Auswertung (bis build 16 wurde die Nummer einmalig gespeichert und blieb dann stehen).
     */
    private function extractSerialNumber(array $buffer): void
    {
        $serial = '';
        foreach (array_slice($buffer, self::OFFSET_SERIAL, 12) as $word) {
            $serial .= chr($word >> 8) . chr($word & 0xFF);
        }
        $serial = trim(preg_replace('/[^\x20-\x7E]/', '', $serial));
        // Die BMU füllt das Feld rechts mit 'x' auf (Be Connect zeigt die Nummer ohne sie).
        $serial = rtrim($serial, 'x');
        if ($serial !== '' && $serial !== $this->ReadAttributeString(self::ATTR_SERIAL)) {
            $this->WriteAttributeString(self::ATTR_SERIAL, $serial);
            $this->SetSummary($serial);
            $this->SendDebug(__FUNCTION__, 'Seriennummer: ' . $serial, 0);
        }
    }
}
