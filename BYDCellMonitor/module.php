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

    private const int REG_INDEX       = 0x0550; // 1360
    private const int REG_CMD         = 0x0551; // 1361 (FC06)
    private const int REG_STATUS      = 0x0551; // 1361 (FC03)
    private const int REG_WINDOW      = 0x0558; // 1368
    private const int REG_STATUSBLOCK = 0x0500; // 1280: SOC, Zellspannungen min/max, SOH, Strom, ...
    private const int REG_INFOBLOCK   = 14;     // 14-17: BMS-Version, ?, Config Word, Batterietyp

    private const int CMD_START        = 0x8100;
    private const int STATUS_READY     = 0x8801;
    private const int STATUS_BAD_INDEX = 0x4000;

    private const int WINDOW_READS   = 5;   // Obergrenze; abgebrochen wird, sobald der Puffer reicht (HVM wie HVS: 4 Lesungen)
    private const int WINDOW_SIZE    = 65;  // 1 Header-Wort + 64 Datenworte
    private const int OFFSET_SERIAL  = 34;  // Worte 34-45 (12 Worte à 2 ASCII-Zeichen)
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
    }

    protected function registerVendorVariables(): void
    {
        $this->ensureVariable('BatteryVoltage', $this->Translate('Battery voltage'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 1,
        ], 12);
        $this->ensureVariable('SOH', $this->Translate('State of health'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0,
        ], 13);
        $this->ensureVariable('CellTempMax', $this->Translate('Max cell temperature'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 0,
        ], 23);
        $this->ensureVariable('CellTempMin', $this->Translate('Min cell temperature'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 0,
        ], 24);
        $this->ensureVariable('ErrorBitmask', $this->Translate('Error bitmask'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'DIGITS' => 0,
        ], 25);
        $this->ensureVariable('EnergyCharged', $this->Translate('Charged energy (BMU)'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' kWh', 'DIGITS' => 1,
        ], 26);
        $this->ensureVariable('EnergyDischarged', $this->Translate('Discharged energy (BMU)'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' kWh', 'DIGITS' => 1,
        ], 27);
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
        $this->SetValue('SOC', $soc);
        $this->SetValue('CellVoltageMax', $words[1] / 100);
        $this->SetValue('CellVoltageMin', $words[2] / 100);
        $this->SetValue('CellDelta', ($words[1] - $words[2]) * 10);
        $this->SetValue('SOH', $words[3]);
        $this->SetValue('Current', self::toSigned16($words[4]) / 10);
        $this->SetValue('BatteryVoltage', $words[5] / 100);
        $this->SetValue('CellTempMax', self::toSigned16($words[6]));
        $this->SetValue('CellTempMin', self::toSigned16($words[7]));
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

        if ($this->ReadAttributeInteger(self::ATTR_DETECTEDMODULES) === 0) {
            $this->detectConfiguration();
        }
        return $soc;
    }

    /** Handshake + wanderndes Fenster: alle Zellspannungen lesen. */
    protected function readCellVoltages(): ?array
    {
        $buffer = $this->withBusLock(function (): ?array {
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
                $this->LogMessage('Handshake-Timeout - Zellmessung abgebrochen', KL_WARNING);
                return null;
            }

            // Soviel Puffer, dass Zellen und Temperaturzone sicher enthalten sind; mehr Lesungen
            // quittiert die BMU je nach Turmgröße mit einer Ausnahme (HVM leere Antwort, HVS Code 4).
            $wunsch = self::OFFSET_TEMPS + $this->moduleCount() * max(
                self::TEMPWORDS_MIN_PER_MODULE,
                (int) ceil($this->ReadPropertyInteger(self::PROP_CELLSPERMODULE) / 4)
            );
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
            $tMax = 0;
            foreach (array_slice($buffer, self::OFFSET_TEMPS + $m * $proModul, $proModul) as $word) {
                $tMax = max($tMax, $word >> 8, $word & 0xFF);
            }
            $temps[$m + 1] = $tMax;
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

    /** Config Word (Register 16) auswerten: Bits 0-3 = Modulzahl, 4-7 = BMS-Anzahl. */
    private function detectConfiguration(): void
    {
        $words = $this->readHoldingRegisters(self::REG_INFOBLOCK, 4);
        if (!is_array($words)) {
            return;
        }
        $modules = $words[2] & 0x0F;
        if ($modules > 0) {
            $this->WriteAttributeInteger(self::ATTR_DETECTEDMODULES, $modules);
            $this->SendDebug(__FUNCTION__, sprintf(
                'Erkannt: %d Module, %d BMS, Batterietyp 0x%02X, BMS-Version V%d.%d',
                $modules,
                ($words[2] >> 4) & 0x0F,
                $words[3] & 0xFF,
                $words[0] >> 8,
                $words[0] & 0xFF
            ), 0);
        }
    }

    private function extractSerialNumber(array $buffer): void
    {
        if ($this->ReadAttributeString(self::ATTR_SERIAL) !== '') {
            return;
        }
        $serial = '';
        foreach (array_slice($buffer, self::OFFSET_SERIAL, 12) as $word) {
            $serial .= chr($word >> 8) . chr($word & 0xFF);
        }
        $serial = trim(preg_replace('/[^\x20-\x7E]/', '', $serial));
        if ($serial !== '') {
            $this->WriteAttributeString(self::ATTR_SERIAL, $serial);
            $this->SetSummary($serial);
        }
    }
}
