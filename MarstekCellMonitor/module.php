<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/CellMonitorBase.php';

/*
 * Marstek Venus E (v3) — liest Zellspannungen und Statuswerte per ModBus-TCP.
 *
 * ACHTUNG: Noch ungetestet (vorbereitet 08/2026, Registerkarte nach
 * ViperRNMC/marstek_venus_modbus, Datei registers/e_v3.yaml). Die Venus E v3
 * ist direkt per Ethernet erreichbar; ältere Venus E brauchen eine
 * RS485-zu-Ethernet-Bridge im ModBus-TCP-Modus (z. B. Elfin EW11, USR DR134).
 *
 * Anders als bei BYD liegen alle Werte dauerhaft in Registern — kein Handshake,
 * kein wanderndes Fenster. Ein Pack mit 16 Zellen (LiFePO4, 51,2 V).
 */
class MarstekCellMonitor extends CellMonitorBase
{
    private const int CELL_COUNT = 16;

    private const int REG_BAT_POWER     = 30001; // W, vorzeichenbehaftet
    private const int REG_BAT_VOLTAGE   = 30100; // x0,01 V
    private const int REG_BAT_CURRENT   = 30101; // x0,1 A, vorzeichenbehaftet
    private const int REG_SOC           = 34002; // x0,1 %
    private const int REG_CYCLES        = 34003;
    private const int REG_CELLS         = 34018; // 16 Register, mV
    private const int REG_TEMP_INTERNAL = 35000; // x0,1 °C
    private const int REG_CELLTEMP_MAX  = 35010; // x0,1 °C
    private const int REG_CELLTEMP_MIN  = 35011; // x0,1 °C
    private const int REG_ALARM         = 36000; // 2 Register Bitfeld
    private const int REG_FAULT         = 36100; // 4 Register Bitfeld
    private const int REG_CELLV_MAX     = 37007; // mV
    private const int REG_CELLV_MIN     = 37008; // mV

    protected function registerVendorVariables(): void
    {
        $this->ensureVariable('Power', $this->Translate('Battery power'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' W', 'DIGITS' => 0,
        ], 12);
        $this->ensureVariable('BatteryVoltage', $this->Translate('Battery voltage'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 2,
        ], 13);
        $this->ensureVariable('InternalTemp', $this->Translate('Internal temperature'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1,
        ], 14);
        $this->ensureVariable('CellTempMax', $this->Translate('Max cell temperature'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1,
        ], 23);
        $this->ensureVariable('CellTempMin', $this->Translate('Min cell temperature'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1,
        ], 24);
        $this->ensureVariable('CycleCount', $this->Translate('Charge cycles'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'DIGITS' => 0,
        ], 25);
        $this->ensureVariable('AlarmBits', $this->Translate('Alarm bits'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 26);
        $this->ensureVariable('FaultBits', $this->Translate('Fault bits'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 27);
    }

    protected function readStatusValues(): ?int
    {
        return $this->withBusLock(function (): ?int {
            $socWords = $this->readHoldingRegisters(self::REG_SOC, 2); // SOC + Zyklenzähler
            if (!is_array($socWords)) {
                return null;
            }
            $soc = (int) round($socWords[0] / 10);
            if ($soc > 100) {
                $this->SendDebug(__FUNCTION__, 'Ungültiger SOC-Rohwert: ' . $socWords[0], 0);
                return null;
            }
            $this->SetValue('SOC', $soc);
            $this->SetValue('CycleCount', $socWords[1]);

            $power = $this->readHoldingRegisters(self::REG_BAT_POWER, 1);
            if (is_array($power)) {
                $this->SetValue('Power', self::toSigned16($power[0]));
            }
            $voltCurrent = $this->readHoldingRegisters(self::REG_BAT_VOLTAGE, 2);
            if (is_array($voltCurrent)) {
                $this->SetValue('BatteryVoltage', $voltCurrent[0] / 100);
                $this->SetValue('Current', self::toSigned16($voltCurrent[1]) / 10);
            }
            $temps = $this->readHoldingRegisters(self::REG_TEMP_INTERNAL, 1);
            if (is_array($temps)) {
                $this->SetValue('InternalTemp', self::toSigned16($temps[0]) / 10);
            }
            $cellTemps = $this->readHoldingRegisters(self::REG_CELLTEMP_MAX, 2);
            if (is_array($cellTemps)) {
                $this->SetValue('CellTempMax', self::toSigned16($cellTemps[0]) / 10);
                $this->SetValue('CellTempMin', self::toSigned16($cellTemps[1]) / 10);
            }
            $cellExtrema = $this->readHoldingRegisters(self::REG_CELLV_MAX, 2);
            if (is_array($cellExtrema)) {
                $this->SetValue('CellVoltageMax', $cellExtrema[0] / 1000);
                $this->SetValue('CellVoltageMin', $cellExtrema[1] / 1000);
                $this->SetValue('CellDelta', $cellExtrema[0] - $cellExtrema[1]);
            }

            $alarm = $this->readHoldingRegisters(self::REG_ALARM, 2);
            if (is_array($alarm)) {
                $this->SetValue('AlarmBits', sprintf('0x%04X%04X', $alarm[0], $alarm[1]));
            }
            $fault = $this->readHoldingRegisters(self::REG_FAULT, 4);
            if (is_array($fault)) {
                $hex = sprintf('0x%04X%04X%04X%04X', $fault[0], $fault[1], $fault[2], $fault[3]);
                $this->SetValue('FaultBits', $hex);
                if (array_sum($fault) !== 0) {
                    $this->LogMessage('Gerät meldet Fehlerbits ' . $hex, KL_ERROR);
                }
            }
            return $soc;
        });
    }

    protected function readCellVoltages(): ?array
    {
        $words = $this->withBusLock(fn(): ?array => $this->readHoldingRegisters(self::REG_CELLS, self::CELL_COUNT));
        if (!is_array($words)) {
            return null;
        }
        // Plausibilität: LiFePO4-Zellen liegen zwischen ~2,0 und ~3,8 V
        $valid = array_filter($words, static fn(int $mv): bool => $mv >= 1500 && $mv <= 4500);
        if (count($valid) !== self::CELL_COUNT) {
            $this->SendDebug(__FUNCTION__, 'Unplausible Zellwerte: ' . implode(' ', $words), 0);
            return null;
        }
        return [1 => $words];
    }
}
