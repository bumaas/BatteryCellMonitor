<?php

declare(strict_types=1);

/*
 * Regressionstest: eine Statusabfrage darf jeden Wert nur einmal schreiben.
 *
 * Gemeldet von erpe im Symcon-Forum (t/144307, Beitrag 48, 04.09.2026): Bei einer
 * Battery-Box mit zwei Türmen springen Ladezustand und Zellspannung im Minutentakt
 * zwischen zwei Werten — im Archiv liegen sie zwei Sekunden auseinander (3,330 V aus
 * dem Statusblock, 3,336 V aus der Turmlesung).
 *
 * Ursache: readStatusValues() schrieb erst die boxweiten Werte des Statusblocks
 * (10-mV-Raster) und readTowerStatus() überschrieb sie unmittelbar danach turmgenau
 * in mV. Bei einem einzelnen Turm läuft der zweite Pfad nie, deshalb fiel es dort
 * nicht auf.
 *
 * Fixtures sind echte Mitschnitte, siehe tests/fixtures/README.md.
 *
 * Aufruf: php tests/check-tower-status-writes.php (Exit-Code 1 bei Fehlern)
 */

foreach ([
    'VARIABLETYPE_BOOLEAN' => 0,
    'VARIABLETYPE_INTEGER' => 1,
    'VARIABLETYPE_FLOAT'   => 2,
    'VARIABLETYPE_STRING'  => 3,
    'KL_MESSAGE'           => 10,
    'KL_SUCCESS'           => 1,
    'KL_NOTIFY'            => 2,
    'KL_WARNING'           => 3,
    'KL_ERROR'             => 4,
] as $name => $wert) {
    if (!defined($name)) {
        define($name, $wert);
    }
}
foreach (['VARIABLE_PRESENTATION_VALUE_PRESENTATION'] as $name) {
    if (!defined($name)) {
        define($name, '{2FCEC3C1-CBD0-4B03-9B5E-2C4DA6DBEA6E}');
    }
}

/** Minimaler Symcon-Ersatz: hält Properties und Attribute und protokolliert jedes SetValue. */
abstract class IPSModuleStrict
{
    /** @var list<array{0: string, 1: mixed}> Reihenfolge aller SetValue-Aufrufe */
    public array $writes = [];
    /** @var list<string> Meldungen, die im Protokoll gelandet wären */
    public array $logs = [];

    protected array $properties = [];
    protected array $attributes = [];
    protected array $buffers    = [];
    protected array $values     = [];

    public int $InstanceID = 12345;

    public function Create(): void {}

    public function RegisterPropertyInteger(string $ident, int $vorgabe): void
    {
        $this->properties[$ident] ??= $vorgabe;
    }

    public function RegisterPropertyBoolean(string $ident, bool $vorgabe): void
    {
        $this->properties[$ident] ??= $vorgabe;
    }

    public function RegisterPropertyString(string $ident, string $vorgabe): void
    {
        $this->properties[$ident] ??= $vorgabe;
    }

    public function RegisterAttributeInteger(string $ident, int $vorgabe): void
    {
        $this->attributes[$ident] ??= $vorgabe;
    }

    public function RegisterAttributeString(string $ident, string $vorgabe): void
    {
        $this->attributes[$ident] ??= $vorgabe;
    }

    public function RegisterTimer(string $ident, int $intervall, string $skript): void {}

    public function ReadPropertyInteger(string $ident): int
    {
        return (int) ($this->properties[$ident] ?? 0);
    }

    public function ReadPropertyBoolean(string $ident): bool
    {
        return (bool) ($this->properties[$ident] ?? false);
    }

    public function ReadAttributeInteger(string $ident): int
    {
        return (int) ($this->attributes[$ident] ?? 0);
    }

    public function ReadAttributeString(string $ident): string
    {
        return (string) ($this->attributes[$ident] ?? '');
    }

    public function WriteAttributeInteger(string $ident, int $wert): void
    {
        $this->attributes[$ident] = $wert;
    }

    public function WriteAttributeString(string $ident, string $wert): void
    {
        $this->attributes[$ident] = $wert;
    }

    public function SetValue(string $ident, mixed $wert): void
    {
        $this->writes[]         = [$ident, $wert];
        $this->values[$ident]   = $wert;
    }

    public function GetValue(string $ident): mixed
    {
        return $this->values[$ident] ?? 0;
    }

    public function SetBuffer(string $ident, string $wert): void
    {
        $this->buffers[$ident] = $wert;
    }

    public function GetBuffer(string $ident): string
    {
        return $this->buffers[$ident] ?? '';
    }

    public function SendDebug(string $kanal, string $text, int $format): void {}

    public function LogMessage(string $text, int $stufe): void
    {
        $this->logs[] = $text;
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function HasActiveParent(): bool
    {
        return true;
    }

    public function GetIDForIdent(string $ident): int
    {
        return 4711;
    }

    public function SetStatus(int $status): void {}
}

if (!function_exists('IPS_GetVariable')) {
    function IPS_GetVariable(int $id): array
    {
        // Stammdaten gelten als frisch gelesen, damit detectConfiguration() nicht anläuft.
        return ['VariableUpdated' => time(), 'VariableChanged' => time()];
    }
}
if (!function_exists('IPS_Sleep')) {
    function IPS_Sleep(int $ms): void {}
}

require_once dirname(__DIR__) . '/BYDCellMonitor/module.php';

/**
 * Ersetzt nur die ModBus-Ebene: der Statusblock kommt aus der Fixture, der Fensterblock
 * ebenfalls (oder gar nicht, um eine gescheiterte Turmlesung nachzustellen).
 */
final class TowerWriteHarness extends BYDCellMonitor
{
    public array $statusWords  = [];
    public ?array $windowWords = null;
    public int $windowCalls    = 0;

    protected function withBusLock(callable $fn): mixed
    {
        return $fn();
    }

    protected function readHoldingRegisters(int $address, int $quantity): ?array
    {
        return array_slice($this->statusWords, 0, $quantity) ?: null;
    }

    protected function readWindow(int $wunsch): ?array
    {
        $this->windowCalls++;
        return $this->windowWords;
    }

    public function statusLesen(): ?int
    {
        return $this->readStatusValues();
    }

    public function setzeProperty(string $ident, int $wert): void
    {
        $this->properties[$ident] = $wert;
    }

    public function setzeAttribut(string $ident, int $wert): void
    {
        $this->attributes[$ident] = $wert;
    }
}

// -- Fixtures ---------------------------------------------------------------

function fixture(string $name): array
{
    $datei = __DIR__ . '/fixtures/' . $name . '.json';
    $daten = json_decode(file_get_contents($datei), true, 512, JSON_THROW_ON_ERROR);
    return $daten['worte'];
}

$statusblock = fixture('statusblock_hvm');
$turm1       = fixture('window_hvs_tower1');

// -- Prüfgerüst -------------------------------------------------------------

$geprueft = 0;
$fehler   = 0;

function pruefe(string $was, mixed $erwartet, mixed $ist): void
{
    global $geprueft, $fehler;
    $geprueft++;
    if ($erwartet === $ist) {
        return;
    }
    $fehler++;
    printf("  FEHLER  %s: erwartet %s, war %s\n", $was, var_export($erwartet, true), var_export($ist, true));
}

/** @return list<mixed> alle Werte, die für diesen Ident geschrieben wurden */
function schreibungen(TowerWriteHarness $modul, string $ident): array
{
    $werte = [];
    foreach ($modul->writes as [$geschriebenerIdent, $wert]) {
        if ($geschriebenerIdent === $ident) {
            $werte[] = $wert;
        }
    }
    return $werte;
}

function baueModul(array $statusblock, ?array $fenster, int $bmsAnzahl): TowerWriteHarness
{
    $modul = new TowerWriteHarness();
    $modul->Create();
    $modul->setzeProperty('BMSIndex', 1);
    $modul->setzeProperty('ModuleCount', 5);
    $modul->setzeProperty('CellsPerModule', 16);
    $modul->setzeAttribut('DetectedModules', 5);
    $modul->setzeAttribut('DetectedBMS', $bmsAnzahl);
    $modul->statusWords = $statusblock;
    $modul->windowWords = $fenster;
    return $modul;
}

// Werte, die je Turm gelten - sie kommen bei mehreren Türmen aus der Turmlesung.
const TURMWERTE = ['SOC', 'SOH', 'CellVoltageMax', 'CellVoltageMin', 'CellDelta', 'CellTempMax', 'CellTempMin', 'BatteryVoltage'];
// Werte, die nur der boxweite Statusblock liefert.
const BOXWERTE = ['Current', 'InternalTemp', 'OutputVoltage', 'ErrorBitmask', 'EnergyCharged', 'EnergyDischarged'];

// -- Fall 1: zwei Türme, Turmlesung liefert Daten ---------------------------

echo "== Zwei Türme, Turmlesung erfolgreich ==\n";
$modul = baueModul($statusblock, $turm1, 2);
$soc   = $modul->statusLesen();

pruefe('Rückgabe ist der Turm-SOC', 33, $soc);
foreach (TURMWERTE as $ident) {
    pruefe(sprintf('%s wird genau einmal geschrieben', $ident), 1, count(schreibungen($modul, $ident)));
}
// Die Turmlesung ist die feinere Quelle - ihre Werte müssen stehen bleiben.
pruefe('CellVoltageMax stammt aus der Turmlesung', [3.27], schreibungen($modul, 'CellVoltageMax'));
pruefe('CellVoltageMin stammt aus der Turmlesung', [3.267], schreibungen($modul, 'CellVoltageMin'));
pruefe('CellDelta stammt aus der Turmlesung', [3], schreibungen($modul, 'CellDelta'));
pruefe('SOC stammt aus der Turmlesung', [33], schreibungen($modul, 'SOC'));
pruefe('SOH stammt aus der Turmlesung', [97], schreibungen($modul, 'SOH'));
pruefe('CellTempMax stammt aus der Turmlesung', [27], schreibungen($modul, 'CellTempMax'));
pruefe('CellTempMin stammt aus der Turmlesung', [24], schreibungen($modul, 'CellTempMin'));
pruefe('BatteryVoltage stammt aus der Turmlesung', [418.4], schreibungen($modul, 'BatteryVoltage'));
foreach (BOXWERTE as $ident) {
    pruefe(sprintf('%s wird genau einmal geschrieben', $ident), 1, count(schreibungen($modul, $ident)));
}
pruefe('Strom kommt weiter aus dem Statusblock', [4.5], schreibungen($modul, 'Current'));
pruefe('Geladene Energie kommt aus dem Statusblock', [5112.4], schreibungen($modul, 'EnergyCharged'));

// -- Fall 2: zwei Türme, Turmlesung scheitert -------------------------------

echo "== Zwei Türme, Turmlesung scheitert ==\n";
$modul = baueModul($statusblock, null, 2);
$soc   = $modul->statusLesen();

pruefe('Rückgabe fällt auf den Box-SOC zurück', 28, $soc);
foreach (TURMWERTE as $ident) {
    pruefe(sprintf('%s wird genau einmal geschrieben', $ident), 1, count(schreibungen($modul, $ident)));
}
pruefe('CellVoltageMax kommt aus dem Statusblock', [3.22], schreibungen($modul, 'CellVoltageMax'));
pruefe('CellDelta kommt aus dem Statusblock', [50], schreibungen($modul, 'CellDelta'));
pruefe('BatteryVoltage kommt aus dem Statusblock', [255.9], schreibungen($modul, 'BatteryVoltage'));

// -- Fall 3: ein Turm -------------------------------------------------------

echo "== Ein Turm ==\n";
$modul = baueModul($statusblock, $turm1, 1);
$soc   = $modul->statusLesen();

pruefe('Rückgabe ist der Box-SOC', 28, $soc);
pruefe('Fensterblock wird gar nicht gelesen', 0, $modul->windowCalls);
foreach (array_merge(TURMWERTE, BOXWERTE) as $ident) {
    pruefe(sprintf('%s wird genau einmal geschrieben', $ident), 1, count(schreibungen($modul, $ident)));
}
pruefe('CellVoltageMax kommt aus dem Statusblock', [3.22], schreibungen($modul, 'CellVoltageMax'));
pruefe('CellTempMax kommt aus dem Statusblock', [32], schreibungen($modul, 'CellTempMax'));
pruefe('BMU-Temperatur kommt aus dem Statusblock', [31], schreibungen($modul, 'InternalTemp'));

printf("\n%d Prüfungen, %d Fehler\n", $geprueft, $fehler);
exit($fehler > 0 ? 1 : 0);
