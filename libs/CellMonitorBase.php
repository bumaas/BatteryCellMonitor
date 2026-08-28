<?php

declare(strict_types=1);

/*
 * Gemeinsame Basis der Zellmonitor-Module (BYD, Marstek).
 *
 * Spricht ModBus (FC03/FC06) direkt über einen Client Socket — ohne
 * ModBus-Gateway/-Geräte-Instanzen und damit auch ohne die Blockabfrage-
 * Korrektur aus Symcon 9.1 (Forum t/143397). Zwei Framings:
 *  - ModBus TCP (MBAP-Header) — Standard, z. B. Marstek Venus E
 *  - ModBus RTU über TCP (CRC16) — BYD-BCU auf Port 8080 (Kindklasse
 *    überschreibt useRtuFraming(); verifiziert per sarnau-Referenz und den
 *    produktiven Symcon-Gateways im Modus "Modbus RTU over TCP")
 *
 * Die Kindklassen liefern die herstellerspezifischen Teile:
 *  - readStatusValues():  zyklische Kurzabfrage (SOC, Strom, …) → Statusvariablen
 *  - readCellVoltages():  vollständige Zellmessung → [Modul → [mV, …]]
 *  - readModuleTemperatures(): optionale Modultemperaturen der letzten Messung
 */

abstract class CellMonitorBase extends IPSModuleStrict
{
    protected const string GUID_CLIENT_SOCKET  = '{3CFF0FD9-E306-41DB-9B5A-9D06D38576C3}';
    protected const string GUID_DATA_TO_PARENT = '{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}';
    protected const string GUID_ARCHIVE        = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    // gemeinsame Eigenschaften
    protected const string PROP_UNITID          = 'UnitID';
    protected const string PROP_TIMEOUT         = 'TimeoutMs';
    protected const string PROP_STATUSINTERVAL  = 'StatusInterval';   // Sekunden, 0 = aus
    protected const string PROP_MEASUREINTERVAL = 'MeasureInterval';  // Minuten, 0 = aus
    protected const string PROP_AUTOFULLSOC     = 'AutoFullSOC';      // Messung am Ladeschluss ab diesem SOC
    protected const string PROP_AUTOEMPTYSOC    = 'AutoEmptySOC';     // Messung am Entladeschluss bis zu diesem SOC
    protected const string PROP_AUTOCOOLDOWN    = 'AutoCooldown';     // Mindestabstand SOC-getriggerter Messungen (s)
    protected const string PROP_CELLMAXWARN     = 'CellMaxWarn';      // mV
    protected const string PROP_CELLMAXCRIT     = 'CellMaxCrit';      // mV
    protected const string PROP_CELLMINWARN     = 'CellMinWarn';      // mV
    protected const string PROP_CELLMINCRIT     = 'CellMinCrit';      // mV
    protected const string PROP_SPREADWARN      = 'SpreadWarn';       // mV je Modul
    protected const string PROP_WARNCOOLDOWN    = 'WarnCooldown';     // s
    protected const string PROP_CRITCOOLDOWN    = 'CritCooldown';     // s
    protected const string PROP_VISUID          = 'VisuID';           // Kachel-Visualisierung für Push, 0 = aus
    protected const string PROP_AUTOLOG         = 'AutoLogging';      // neue Variablen im Archiv protokollieren
    protected const string PROP_KEEPRAW         = 'KeepRawData';      // Rohdaten der letzten Messung als JSON ablegen

    // Attribute
    private const string ATTR_LASTAUTO = 'LastAutoMeasure';
    private const string ATTR_LASTWARN = 'LastWarnPush';
    private const string ATTR_LASTCRIT = 'LastCritPush';

    /**
     * Verbindet die Instanz mit einem Client Socket (erzeugt ihn bei Bedarf).
     * Ersetzt das frühere ConnectParent im Create - das wirft auf neueren
     * Symcon-Versionen "ConnectParent is not available anymore".
     */
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'connect', 'moduleIDs' => [self::GUID_CLIENT_SOCKET]], JSON_THROW_ON_ERROR);
    }

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyInteger(self::PROP_UNITID, 1);
        $this->RegisterPropertyInteger(self::PROP_TIMEOUT, 2000);
        $this->RegisterPropertyInteger(self::PROP_STATUSINTERVAL, 60);
        $this->RegisterPropertyInteger(self::PROP_MEASUREINTERVAL, 60);
        $this->RegisterPropertyInteger(self::PROP_AUTOFULLSOC, 99);
        $this->RegisterPropertyInteger(self::PROP_AUTOEMPTYSOC, 5);
        $this->RegisterPropertyInteger(self::PROP_AUTOCOOLDOWN, 7200);
        $this->RegisterPropertyInteger(self::PROP_CELLMAXWARN, 3570);
        $this->RegisterPropertyInteger(self::PROP_CELLMAXCRIT, 3650);
        $this->RegisterPropertyInteger(self::PROP_CELLMINWARN, 2850);
        $this->RegisterPropertyInteger(self::PROP_CELLMINCRIT, 2600);
        $this->RegisterPropertyInteger(self::PROP_SPREADWARN, 250);
        $this->RegisterPropertyInteger(self::PROP_WARNCOOLDOWN, 86400);
        $this->RegisterPropertyInteger(self::PROP_CRITCOOLDOWN, 14400);
        $this->RegisterPropertyInteger(self::PROP_VISUID, 0);
        $this->RegisterPropertyBoolean(self::PROP_AUTOLOG, true);
        $this->RegisterPropertyBoolean(self::PROP_KEEPRAW, false);

        $this->RegisterAttributeInteger(self::ATTR_LASTAUTO, 0);
        $this->RegisterAttributeInteger(self::ATTR_LASTWARN, 0);
        $this->RegisterAttributeInteger(self::ATTR_LASTCRIT, 0);

        $this->RegisterTimer('StatusPoll', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerStatus", 0);');
        $this->RegisterTimer('CellMeasure', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], "TimerMeasure", 0);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->ensureVariable('SOC', $this->Translate('State of charge'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0,
        ], 10);
        $this->ensureVariable('Current', $this->Translate('Battery current'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' A', 'DIGITS' => 1,
        ], 11);
        $this->ensureVariable('CellVoltageMax', $this->Translate('Max cell voltage'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 3,
        ], 20);
        $this->ensureVariable('CellVoltageMin', $this->Translate('Min cell voltage'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 3,
        ], 21);
        $this->ensureVariable('CellDelta', $this->Translate('Cell delta'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' mV', 'DIGITS' => 0,
        ], 22);
        $this->ensureVariable('LastMeasurement', $this->Translate('Last cell measurement'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME,
        ], 80);
        $this->ensureVariable('SOCAtMeasurement', $this->Translate('SOC at measurement'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %', 'DIGITS' => 0,
        ], 81);
        $this->ensureVariable('CurrentAtMeasurement', $this->Translate('Current at measurement'), VARIABLETYPE_FLOAT, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' A', 'DIGITS' => 1,
        ], 82);
        $this->ensureVariable('LastAlert', $this->Translate('Last alert'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
        ], 90);
        if ($this->ReadPropertyBoolean(self::PROP_KEEPRAW)) {
            $this->ensureVariable('RawData', $this->Translate('Raw data of last measurement'), VARIABLETYPE_STRING, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
            ], 95);
        }

        $this->registerVendorVariables();

        $this->SetTimerInterval('StatusPoll', $this->ReadPropertyInteger(self::PROP_STATUSINTERVAL) * 1000);
        $this->SetTimerInterval('CellMeasure', $this->ReadPropertyInteger(self::PROP_MEASUREINTERVAL) * 60000);
    }

    public function RequestAction(string $ident, mixed $value): void
    {
        switch ($ident) {
            case 'TimerStatus':
                $this->PollStatus();
                break;
            case 'TimerMeasure':
            case 'MeasureNow':
                $this->Measure();
                break;
            case 'TxTest':
                // Diagnose: beliebige Hex-Bytes roh über den Datenfluss senden (z. B. '01030500...')
                $this->SendDataToParent(json_encode([
                    'DataID' => self::GUID_DATA_TO_PARENT,
                    'Buffer' => mb_convert_encoding(hex2bin((string) $value), 'UTF-8', 'ISO-8859-1'),
                ], JSON_THROW_ON_ERROR));
                break;
            case 'TestPush':
                $this->sendPush(
                    $this->Translate('Cell monitor test'),
                    $this->Translate('Test notification - the push channel is working.'),
                    false,
                    true
                );
                break;
            default:
                throw new InvalidArgumentException('Invalid ident: ' . $ident);
        }
    }

    /** Zyklische Kurzabfrage: Statuswerte lesen und SOC-Trigger prüfen. */
    public function PollStatus(): bool
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug(__FUNCTION__, 'Kein aktiver Client Socket - Abfrage übersprungen', 0);
            return false;
        }
        $soc = $this->readStatusValues();
        if ($soc === null) {
            return false;
        }
        $this->maybeAutoMeasure($soc);
        return true;
    }

    /** Vollständige Zellmessung ausführen. */
    public function Measure(): bool
    {
        if (!$this->HasActiveParent()) {
            $this->SendDebug(__FUNCTION__, 'Kein aktiver Client Socket - Messung übersprungen', 0);
            return false;
        }
        $cellsByModule = $this->readCellVoltages();
        if ($cellsByModule === null) {
            return false;
        }
        $this->processCellVoltages($cellsByModule, $this->readModuleTemperatures());
        return true;
    }

    // -- vom Hersteller-Modul zu implementieren ------------------------------

    /** Statuswerte lesen, Statusvariablen setzen; Rückgabe: aktueller SOC in % (oder null bei Fehler). */
    abstract protected function readStatusValues(): ?int;

    /** Alle Zellspannungen lesen; Rückgabe: [Modulnummer ab 1 => [mV, ...]] (oder null bei Fehler). */
    abstract protected function readCellVoltages(): ?array;

    /** Modultemperaturen der letzten Messung; Rückgabe: [Modulnummer => Max-Temperatur °C] (leer, wenn nicht verfügbar). */
    protected function readModuleTemperatures(): array
    {
        return [];
    }

    /** Herstellerspezifische Zusatzvariablen anlegen (wird aus ApplyChanges gerufen). */
    abstract protected function registerVendorVariables(): void;

    // -- Auswertung ----------------------------------------------------------

    protected function processCellVoltages(array $cellsByModule, array $tempsByModule): void
    {
        $allCells  = [];
        $warnings  = [];
        $criticals = [];
        $maxWarn   = $this->ReadPropertyInteger(self::PROP_CELLMAXWARN);
        $maxCrit   = $this->ReadPropertyInteger(self::PROP_CELLMAXCRIT);
        $minWarn   = $this->ReadPropertyInteger(self::PROP_CELLMINWARN);
        $minCrit   = $this->ReadPropertyInteger(self::PROP_CELLMINCRIT);
        $spreadMax = $this->ReadPropertyInteger(self::PROP_SPREADWARN);

        $cellsPerModule = count(reset($cellsByModule) ?: []);
        foreach ($cellsByModule as $module => $cells) {
            $min    = min($cells);
            $max    = max($cells);
            $spread = $max - $min;

            $this->ensureVariable("Module{$module}Min", sprintf($this->Translate('Module %d cell voltage min'), $module), VARIABLETYPE_FLOAT, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 3,
            ], 30 + $module * 10);
            $this->ensureVariable("Module{$module}Max", sprintf($this->Translate('Module %d cell voltage max'), $module), VARIABLETYPE_FLOAT, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' V', 'DIGITS' => 3,
            ], 31 + $module * 10);
            $this->ensureVariable("Module{$module}Spread", sprintf($this->Translate('Module %d spread'), $module), VARIABLETYPE_INTEGER, [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' mV', 'DIGITS' => 0,
            ], 32 + $module * 10);
            $this->SetValue("Module{$module}Min", $min / 1000);
            $this->SetValue("Module{$module}Max", $max / 1000);
            $this->SetValue("Module{$module}Spread", $spread);

            if (isset($tempsByModule[$module])) {
                $this->ensureVariable("Module{$module}TempMax", sprintf($this->Translate('Module %d temperature max'), $module), VARIABLETYPE_FLOAT, [
                    'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' °C', 'DIGITS' => 1,
                ], 33 + $module * 10);
                $this->SetValue("Module{$module}TempMax", (float) $tempsByModule[$module]);
            }

            foreach ($cells as $i => $mv) {
                $cellNo = ($module - 1) * $cellsPerModule + $i + 1;
                if ($mv >= $maxCrit) {
                    $criticals[] = sprintf($this->Translate('Cell %1$d (module %2$d): %3$d mV above protection limit'), $cellNo, $module, $mv);
                } elseif ($mv >= $maxWarn) {
                    $warnings[] = sprintf($this->Translate('Cell %1$d (module %2$d): %3$d mV'), $cellNo, $module, $mv);
                }
                if ($mv > 0 && $mv <= $minCrit) {
                    $criticals[] = sprintf($this->Translate('Cell %1$d (module %2$d): only %3$d mV'), $cellNo, $module, $mv);
                } elseif ($mv > 0 && $mv <= $minWarn) {
                    $warnings[] = sprintf($this->Translate('Cell %1$d (module %2$d): only %3$d mV'), $cellNo, $module, $mv);
                }
            }
            if ($spread >= $spreadMax) {
                $warnings[] = sprintf($this->Translate('Module %1$d: spread %2$d mV'), $module, $spread);
            }

            $allCells = array_merge($allCells, $cells);
        }

        $soc     = $this->GetValue('SOC');
        $current = $this->GetValue('Current');
        $this->SetValue('CellVoltageMax', max($allCells) / 1000);
        $this->SetValue('CellVoltageMin', min($allCells) / 1000);
        $this->SetValue('CellDelta', max($allCells) - min($allCells));
        $this->SetValue('LastMeasurement', time());
        $this->SetValue('SOCAtMeasurement', (int) $soc);
        $this->SetValue('CurrentAtMeasurement', (float) $current);

        if ($this->ReadPropertyBoolean(self::PROP_KEEPRAW)) {
            $this->SetValue('RawData', json_encode([
                'time'    => date('c'),
                'soc'     => $soc,
                'current' => $current,
                'cells'   => $cellsByModule,
                'temps'   => $tempsByModule,
            ], JSON_THROW_ON_ERROR));
        }

        $this->SendDebug(__FUNCTION__, sprintf(
            'Turm: %d-%d mV, Delta %d mV, %d Warnungen, %d kritisch',
            min($allCells),
            max($allCells),
            max($allCells) - min($allCells),
            count($warnings),
            count($criticals)
        ), 0);

        if ($criticals !== [] || $warnings !== []) {
            $text = sprintf('SOC %d %%, %.1f A - %s', $soc, $current, implode('; ', array_merge($criticals, $warnings)));
            $this->SetValue('LastAlert', date('d.m.Y H:i') . ' ' . $text);
            $this->LogMessage(($criticals !== [] ? 'KRITISCH: ' : 'Warnung: ') . $text, $criticals !== [] ? KL_ERROR : KL_WARNING);
            $this->sendPush(
                $criticals !== [] ? $this->Translate('Cell monitor: CRITICAL') : $this->Translate('Cell monitor: warning'),
                $text,
                $criticals !== []
            );
        }
    }

    private function maybeAutoMeasure(int $soc): void
    {
        if ($soc < $this->ReadPropertyInteger(self::PROP_AUTOFULLSOC) && $soc > $this->ReadPropertyInteger(self::PROP_AUTOEMPTYSOC)) {
            return;
        }
        if (time() - $this->ReadAttributeInteger(self::ATTR_LASTAUTO) < $this->ReadPropertyInteger(self::PROP_AUTOCOOLDOWN)) {
            return;
        }
        $this->WriteAttributeInteger(self::ATTR_LASTAUTO, time());
        $this->SendDebug(__FUNCTION__, 'SOC-Trigger bei ' . $soc . ' % - starte Zellmessung', 0);
        $this->Measure();
    }

    private function sendPush(string $title, string $text, bool $critical, bool $force = false): void
    {
        $visuID = $this->ReadPropertyInteger(self::PROP_VISUID);
        if ($visuID <= 0 || !IPS_InstanceExists($visuID)) {
            return;
        }
        if (!$force) {
            $attr     = $critical ? self::ATTR_LASTCRIT : self::ATTR_LASTWARN;
            $cooldown = $this->ReadPropertyInteger($critical ? self::PROP_CRITCOOLDOWN : self::PROP_WARNCOOLDOWN);
            if (time() - $this->ReadAttributeInteger($attr) < $cooldown) {
                return;
            }
            $this->WriteAttributeInteger($attr, time());
        }
        try {
            if (function_exists('VISU_PostNotification')) {
                VISU_PostNotification($visuID, $title, $text, 'Battery', 0);
            } elseif (function_exists('WFC_PushNotification')) {
                WFC_PushNotification($visuID, $title, $text, '', 0);
            }
        } catch (Throwable $e) {
            $this->LogMessage('Push fehlgeschlagen: ' . $e->getMessage(), KL_WARNING);
        }
    }

    // -- ModBus-TCP über den Client Socket -----------------------------------

    public function ReceiveData(string $JSONString): string
    {
        $data  = json_decode($JSONString, true, 512, JSON_THROW_ON_ERROR);
        $chunk = mb_convert_encoding($data['Buffer'], 'ISO-8859-1', 'UTF-8');
        $this->SetBuffer('RxBuffer', base64_encode(base64_decode($this->GetBuffer('RxBuffer')) . $chunk));
        return '';
    }

    /** Mehrere Register lesen (FC03); Rückgabe als Array vorzeichenloser 16-Bit-Worte. */
    protected function readHoldingRegisters(int $address, int $quantity): ?array
    {
        $pdu      = pack('Cnn', 3, $address, $quantity);
        $response = $this->modbusTransaction($pdu);
        if ($response === null) {
            return null;
        }
        $fc = ord($response[0]);
        if ($fc === 0x83) {
            $this->SendDebug(__FUNCTION__, sprintf('ModBus-Ausnahme %d beim Lesen ab %d', ord($response[1]), $address), 0);
            return null;
        }
        if ($fc !== 3 || strlen($response) < 2) {
            return null;
        }
        $byteCount = ord($response[1]);
        if (strlen($response) < 2 + $byteCount) {
            return null;
        }
        $words = array_values(unpack('n*', substr($response, 2, $byteCount)));
        return count($words) === $quantity ? $words : null;
    }

    /** Einzelnes Register schreiben (FC06). Die BYD-BMU lehnt FC16-Kombi-Writes ab. */
    protected function writeSingleRegister(int $address, int $value): bool
    {
        $pdu      = pack('Cnn', 6, $address, $value & 0xFFFF);
        $response = $this->modbusTransaction($pdu);
        if ($response === null) {
            return false;
        }
        if (ord($response[0]) === 0x86) {
            $this->SendDebug(__FUNCTION__, sprintf('ModBus-Ausnahme %d beim Schreiben auf %d', ord($response[1]), $address), 0);
            return false;
        }
        return ord($response[0]) === 6;
    }

    /** Framing der Kindklasse: false = ModBus TCP (MBAP), true = ModBus RTU über TCP (CRC16). */
    protected function useRtuFraming(): bool
    {
        return false;
    }

    /** Eine ModBus-Transaktion im Framing der Kindklasse. Rückgabe: Antwort-PDU ohne Unit-ID. */
    private function modbusTransaction(string $pdu): ?string
    {
        return $this->useRtuFraming() ? $this->rtuTransaction($pdu) : $this->mbapTransaction($pdu);
    }

    /**
     * ModBus-TCP-Transaktion: MBAP-Header bauen, senden, auf die Antwort
     * mit passender Transaktions-ID warten.
     */
    private function mbapTransaction(string $pdu): ?string
    {
        $tid = $this->nextTransactionID();
        $frame = pack('nnnC', $tid, 0, strlen($pdu) + 1, $this->ReadPropertyInteger(self::PROP_UNITID)) . $pdu;

        $this->SetBuffer('RxBuffer', '');
        $this->SendDataToParent(json_encode([
            'DataID' => self::GUID_DATA_TO_PARENT,
            'Buffer' => mb_convert_encoding($frame, 'UTF-8', 'ISO-8859-1'),
        ], JSON_THROW_ON_ERROR));

        $deadline = microtime(true) + $this->ReadPropertyInteger(self::PROP_TIMEOUT) / 1000;
        while (microtime(true) < $deadline) {
            IPS_Sleep(20);
            $rx = base64_decode($this->GetBuffer('RxBuffer'));
            while (strlen($rx) >= 7) {
                $header = unpack('ntid/nproto/nlen', substr($rx, 0, 6));
                if (strlen($rx) < 6 + $header['len']) {
                    break; // Frame noch unvollständig
                }
                $unitAndPdu = substr($rx, 6, $header['len']);
                $rx         = substr($rx, 6 + $header['len']);
                $this->SetBuffer('RxBuffer', base64_encode($rx));
                if ($header['tid'] === $tid) {
                    return substr($unitAndPdu, 1);
                }
                // Antwort einer anderen Instanz am selben Socket - überspringen
            }
        }
        $this->SendDebug(__FUNCTION__, 'Timeout - keine Antwort vom Gerät', 0);
        return null;
    }

    /**
     * ModBus-RTU-über-TCP-Transaktion: Unit + PDU + CRC16, ohne Transaktions-ID.
     * Die Zuordnung sichert die Serialisierung über die Bus-Semaphore; der
     * Empfangspuffer wird vor jedem Request geleert.
     */
    private function rtuTransaction(string $pdu): ?string
    {
        $unit  = $this->ReadPropertyInteger(self::PROP_UNITID);
        $frame = chr($unit) . $pdu;
        $frame .= pack('v', self::crc16($frame));

        $this->SetBuffer('RxBuffer', '');
        $this->SendDataToParent(json_encode([
            'DataID' => self::GUID_DATA_TO_PARENT,
            'Buffer' => mb_convert_encoding($frame, 'UTF-8', 'ISO-8859-1'),
        ], JSON_THROW_ON_ERROR));

        $deadline = microtime(true) + $this->ReadPropertyInteger(self::PROP_TIMEOUT) / 1000;
        while (microtime(true) < $deadline) {
            IPS_Sleep(20);
            $rx     = base64_decode($this->GetBuffer('RxBuffer'));
            $length = self::rtuFrameLength($rx);
            if ($length === null || strlen($rx) < $length) {
                continue; // Frame noch unvollständig
            }
            $frameIn = substr($rx, 0, $length);
            $this->SetBuffer('RxBuffer', base64_encode(substr($rx, $length)));
            if (unpack('v', substr($frameIn, -2))[1] !== self::crc16(substr($frameIn, 0, -2))) {
                $this->SendDebug(__FUNCTION__, 'CRC-Fehler in der Antwort - verworfen', 0);
                return null;
            }
            if (ord($frameIn[0]) !== $unit) {
                continue; // fremde Unit-ID - überspringen
            }
            return substr($frameIn, 1, -2);
        }
        $this->SendDebug(__FUNCTION__, 'Timeout - keine Antwort vom Gerät', 0);
        return null;
    }

    /** Erwartete Gesamtlänge eines RTU-Antwortframes anhand des Funktionscodes (null = noch unbestimmbar). */
    private static function rtuFrameLength(string $rx): ?int
    {
        if (strlen($rx) < 3) {
            return null;
        }
        $fc = ord($rx[1]);
        if (($fc & 0x80) !== 0) {
            return 5; // Unit + FC + Ausnahmecode + CRC
        }
        return match ($fc) {
            3, 4 => 5 + ord($rx[2]), // Unit + FC + Bytezahl + Daten + CRC
            5, 6, 15, 16 => 8,
            default => null,
        };
    }

    protected static function crc16(string $data): int
    {
        $crc = 0xFFFF;
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $crc ^= ord($data[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) !== 0 ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }
        return $crc;
    }

    private function nextTransactionID(): int
    {
        $counter = ((int) $this->GetBuffer('TidCounter') + 1) & 0xFF;
        $this->SetBuffer('TidCounter', (string) $counter);
        return (($this->InstanceID & 0xFF) << 8) | $counter;
    }

    /**
     * Exklusiven Zugriff auf die ModBus-Strecke sichern - je Socket, nicht je
     * Instanz: Bei zwei Türmen an derselben BCU dürfen sich Handshake und
     * Fensterlesungen nicht überlappen.
     */
    protected function withBusLock(callable $fn): mixed
    {
        $parentID = IPS_GetInstance($this->InstanceID)['ConnectionID'];
        $lock     = 'CellMonitorBus_' . $parentID;
        if (!IPS_SemaphoreEnter($lock, 10000)) {
            $this->LogMessage('ModBus-Strecke belegt - Messung übersprungen', KL_WARNING);
            return null;
        }
        try {
            return $fn();
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    protected static function toSigned16(int $word): int
    {
        return $word >= 0x8000 ? $word - 0x10000 : $word;
    }

    // -- Variablenverwaltung -------------------------------------------------

    /** Variable anlegen, falls neu; neue numerische Variablen optional im Archiv protokollieren. */
    protected function ensureVariable(string $ident, string $name, int $type, array $presentation, int $position): void
    {
        $existed = @$this->GetIDForIdent($ident) !== false;
        switch ($type) {
            case VARIABLETYPE_BOOLEAN:
                $this->RegisterVariableBoolean($ident, $name, $presentation, $position);
                break;
            case VARIABLETYPE_INTEGER:
                $this->RegisterVariableInteger($ident, $name, $presentation, $position);
                break;
            case VARIABLETYPE_FLOAT:
                $this->RegisterVariableFloat($ident, $name, $presentation, $position);
                break;
            default:
                $this->RegisterVariableString($ident, $name, $presentation, $position);
        }
        if (!$existed && $type !== VARIABLETYPE_STRING && $this->ReadPropertyBoolean(self::PROP_AUTOLOG)) {
            $archiveIDs = IPS_GetInstanceListByModuleID(self::GUID_ARCHIVE);
            if ($archiveIDs !== []) {
                AC_SetLoggingStatus($archiveIDs[0], $this->GetIDForIdent($ident), true);
                IPS_ApplyChanges($archiveIDs[0]);
            }
        }
    }
}
