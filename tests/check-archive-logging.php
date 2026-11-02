<?php

declare(strict_types=1);

/*
 * Regressionstest: Archiv-Logging nur nach Zustimmung des Benutzers.
 *
 * Anlass ist das Store-Vorab-Review vom 02.11.2026 (Punkte 11 und 13/5):
 *  - Bis build 33 stand die Eigenschaft AutoLogging ab Werk auf true. Jede neu
 *    angelegte numerische Variable kam damit ohne Zutun des Benutzers ins Archiv,
 *    die Energiezähler zusätzlich mit der Aggregation „Zähler“.
 *  - Danach rief ensureVariable() IPS_ApplyChanges() auf die Archivinstanz auf,
 *    eine fremde Kerninstanz. Laut Doku (AC_SetLoggingStatus) wirkt das Logging
 *    sofort, der Aufruf war also auch fachlich unnötig.
 *
 * Erwartet wird jetzt:
 *  - Vorgabe AutoLogging = false, eine neue Instanz loggt nichts.
 *  - Mit AutoLogging = true (Häkchen des Benutzers) werden neue numerische Variablen
 *    geloggt, Zähler bekommen Aggregationstyp 1, IPS_ApplyChanges fällt weg.
 *  - Der Knopf „Enable archive logging for all variables“ (Ident EnableArchiveLogging)
 *    nimmt alle eigenen numerischen Variablen ins Archiv, auch schon vorhandene,
 *    setzt bei Zählern den Typ 1 und stößt eine Reaggregation an, wenn die Variable
 *    vorher schon mit anderem Typ geloggt wurde (Doku AC_SetAggregationType:
 *    „Im Anschluss ist eine Reaggregation der Variable nötig“).
 *  - Ohne Archivinstanz legt das Modul keine an und bricht sauber ab.
 *  - Erst Logging, dann Aggregationstyp (Code-Review 02.11.2026, Befund 3). Build 34 drehte
 *    die Reihenfolge um. Das Archiv lehnt AC_SetAggregationType für eine noch nicht
 *    geloggte Variable aber ab: Am 02.11.2026 auf der Testbox (Linux) und am nuc
 *    (Windows, Rust-Edition) gemessen, Rückgabe false, der Typ bleibt 0, auch nach dem
 *    Einschalten des Loggings. Die Attrappe AC_SetAggregationType bildet genau das nach.
 *
 * Keine Fixture nötig: geprüft wird der Umgang mit dem Archiv, kein Geräteformat.
 *
 * Aufruf: php tests/check-archive-logging.php (Exit-Code 1 bei Fehlern)
 */

// Echte Werte aus dem PhpStorm-Stub (symcon.php, Kernel 9.x).
foreach ([
    'VARIABLETYPE_BOOLEAN'                     => 0,
    'VARIABLETYPE_INTEGER'                     => 1,
    'VARIABLETYPE_FLOAT'                       => 2,
    'VARIABLETYPE_STRING'                      => 3,
    'IS_ACTIVE'                                => 102,
    'KL_WARNING'                               => 10204,
    'KR_READY'                                 => 10103,
    'IPS_KERNELSTARTED'                        => 10001,
    'IM_CHANGESTATUS'                          => 10505,
    'FM_CONNECT'                               => 11101,
    'FM_DISCONNECT'                            => 11102,
    'VARIABLE_PRESENTATION_VALUE_PRESENTATION' => '{3319437D-7CDE-699D-750A-3C6A3841FA75}',
    'VARIABLE_PRESENTATION_DATE_TIME'          => '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}',
] as $name => $wert) {
    if (!defined($name)) {
        define($name, $wert);
    }
}

const ARCHIV_ID   = 10000;
const GUID_ARCHIV = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

/** Archiv- und Kernelzustand der Attrappe. */
final class Kernel
{
    public static bool $archivVorhanden = true;
    /** @var array<int, bool> */
    public static array $logging = [];
    /** @var array<int, int> */
    public static array $aggregation = [];
    /** @var list<int> */
    public static array $reaggregiert = [];
    /** @var list<int> */
    public static array $applyChanges = [];
    /** @var array<int, array{ident: string, typ: int, parent: int}> */
    public static array $variablen = [];
    public static int $naechsteID = 20000;

    public static function zuruecksetzen(): void
    {
        self::$archivVorhanden = true;
        self::$logging         = [];
        self::$aggregation     = [];
        self::$reaggregiert    = [];
        self::$applyChanges    = [];
        self::$variablen       = [];
        self::$naechsteID      = 20000;
    }

    public static function idFuer(int $parent, string $ident): int|false
    {
        foreach (self::$variablen as $id => $v) {
            if ($v['parent'] === $parent && $v['ident'] === $ident) {
                return $id;
            }
        }
        return false;
    }
}

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return ($guid === GUID_ARCHIV && Kernel::$archivVorhanden) ? [ARCHIV_ID] : [];
}

/** Instanz ohne Client Socket; der Status spielt hier keine Rolle (siehe check-instance-status.php). */
function IPS_GetInstance(int $id): array
{
    return ['InstanceID' => $id, 'ConnectionID' => 0, 'InstanceStatus' => 102];
}

function IPS_GetKernelRunlevel(): int
{
    return KR_READY;
}

function IPS_ApplyChanges(int $id): bool
{
    Kernel::$applyChanges[] = $id;
    return true;
}

function IPS_GetChildrenIDs(int $id): array
{
    return array_keys(array_filter(Kernel::$variablen, static fn(array $v): bool => $v['parent'] === $id));
}

function IPS_VariableExists(int $id): bool
{
    return isset(Kernel::$variablen[$id]);
}

function IPS_GetObject(int $id): array
{
    return ['ObjectID' => $id, 'ObjectIdent' => Kernel::$variablen[$id]['ident'], 'ObjectType' => 2];
}

function IPS_GetVariable(int $id): array
{
    return ['VariableID' => $id, 'VariableType' => Kernel::$variablen[$id]['typ']];
}

function AC_GetLoggingStatus(int $archiv, int $var): bool
{
    return Kernel::$logging[$var] ?? false;
}

function AC_SetLoggingStatus(int $archiv, int $var, bool $aktiv): bool
{
    Kernel::$logging[$var] = $aktiv;
    return true;
}

function AC_GetAggregationType(int $archiv, int $var): int
{
    return Kernel::$aggregation[$var] ?? 0;
}

/** Wie das echte Archiv: für eine nicht geloggte Variable false, der Typ bleibt unverändert. */
function AC_SetAggregationType(int $archiv, int $var, int $typ): bool
{
    if (!(Kernel::$logging[$var] ?? false)) {
        return false;
    }
    Kernel::$aggregation[$var] = $typ;
    return true;
}

function AC_ReAggregateVariable(int $archiv, int $var): bool
{
    Kernel::$reaggregiert[] = $var;
    return true;
}

/** Minimaler Symcon-Ersatz - nur, was Create(), ApplyChanges() und RequestAction() brauchen. */
abstract class IPSModuleStrict
{
    public int $InstanceID = 12345;
    /** @var list<string> */
    public array $ausgaben = [];

    protected array $properties = [];

    public function Create(): void {}

    public function ApplyChanges(): void {}

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {}

    public function RegisterMessage(int $sender, int $msg): bool { return true; }

    public function UnregisterMessage(int $sender, int $msg): bool { return true; }

    public function GetMessageList(): array { return []; }

    public function GetStatus(): int { return 102; }

    public function SetStatus(int $status): bool { return true; }

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

    public function RegisterAttributeInteger(string $ident, int $vorgabe): void {}

    public function RegisterAttributeString(string $ident, string $vorgabe): void {}

    public function RegisterTimer(string $ident, int $intervall, string $skript): void {}

    public function SetTimerInterval(string $ident, int $intervall): void {}

    public function ReadPropertyInteger(string $ident): int
    {
        return (int) ($this->properties[$ident] ?? 0);
    }

    public function ReadPropertyBoolean(string $ident): bool
    {
        return (bool) ($this->properties[$ident] ?? false);
    }

    public function setzeProperty(string $ident, mixed $wert): void
    {
        $this->properties[$ident] = $wert;
    }

    public function Translate(string $text): string
    {
        return $text;
    }

    public function SendDebug(string $kanal, string $text, int $format): void {}

    public function LogMessage(string $text, int $typ): void {}

    public function GetIDForIdent(string $ident): int|false
    {
        return Kernel::idFuer($this->InstanceID, $ident);
    }

    private function registriere(string $ident, int $typ): void
    {
        if (Kernel::idFuer($this->InstanceID, $ident) === false) {
            Kernel::$variablen[Kernel::$naechsteID++] = ['ident' => $ident, 'typ' => $typ, 'parent' => $this->InstanceID];
        }
    }

    public function RegisterVariableBoolean(string $ident, string $name, array|string $p = '', int $pos = 0): void
    {
        $this->registriere($ident, VARIABLETYPE_BOOLEAN);
    }

    public function RegisterVariableInteger(string $ident, string $name, array|string $p = '', int $pos = 0): void
    {
        $this->registriere($ident, VARIABLETYPE_INTEGER);
    }

    public function RegisterVariableFloat(string $ident, string $name, array|string $p = '', int $pos = 0): void
    {
        $this->registriere($ident, VARIABLETYPE_FLOAT);
    }

    public function RegisterVariableString(string $ident, string $name, array|string $p = '', int $pos = 0): void
    {
        $this->registriere($ident, VARIABLETYPE_STRING);
    }
}

require_once dirname(__DIR__) . '/BYDCellMonitor/module.php';
require_once dirname(__DIR__) . '/MarstekCellMonitor/module.php';

// -- Prüfgerüst ---------------------------------------------------------------

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

/** Knopf auslösen; eine Exception (alter Stand: unbekannter Ident) zählt als Fehlschlag. */
function knopf(CellMonitorBase $modul): bool
{
    ob_start();
    try {
        $modul->RequestAction('EnableArchiveLogging', 0);
        $modul->ausgaben[] = (string) ob_get_clean();
        return true;
    } catch (Throwable $e) {
        ob_end_clean();
        echo '  (RequestAction: ' . $e->getMessage() . ")\n";
        return false;
    }
}

function geloggt(int $instanz, string $ident): bool
{
    $id = Kernel::idFuer($instanz, $ident);
    return $id !== false && (Kernel::$logging[$id] ?? false);
}

function typ(int $instanz, string $ident): int
{
    $id = Kernel::idFuer($instanz, $ident);
    return $id === false ? -1 : (Kernel::$aggregation[$id] ?? 0);
}

// -- Fall 1: neue Instanz mit Vorgaben loggt nichts --------------------------

echo "== Neue BYD-Instanz mit Vorgaben (Punkt 11) ==\n";
Kernel::zuruecksetzen();
$byd = new BYDCellMonitor();
$byd->Create();
$byd->ApplyChanges();
pruefe('keine Variable wird ohne Zustimmung geloggt', [], array_keys(array_filter(Kernel::$logging)));
pruefe('kein Aggregationstyp wird ohne Zustimmung gesetzt', [], Kernel::$aggregation);
pruefe('kein IPS_ApplyChanges auf die Archivinstanz', [], Kernel::$applyChanges);

echo "== Neue Marstek-Instanz mit Vorgaben (Punkt 11) ==\n";
Kernel::zuruecksetzen();
$mst = new MarstekCellMonitor();
$mst->Create();
$mst->ApplyChanges();
pruefe('keine Variable wird ohne Zustimmung geloggt', [], array_keys(array_filter(Kernel::$logging)));
pruefe('kein IPS_ApplyChanges auf die Archivinstanz', [], Kernel::$applyChanges);

// -- Fall 2: Häkchen gesetzt, neue Variablen kommen ins Archiv ---------------

echo "== BYD mit AutoLogging = true ==\n";
Kernel::zuruecksetzen();
$byd = new BYDCellMonitor();
$byd->Create();
$byd->setzeProperty('AutoLogging', true);
$byd->ApplyChanges();
pruefe('SOC wird geloggt', true, geloggt($byd->InstanceID, 'SOC'));
pruefe('BatteryVoltage wird geloggt', true, geloggt($byd->InstanceID, 'BatteryVoltage'));
pruefe('BMUVersion (String) wird nicht geloggt', false, geloggt($byd->InstanceID, 'BMUVersion'));
pruefe('EnergyCharged bekommt Typ Zähler', 1, typ($byd->InstanceID, 'EnergyCharged'));
pruefe('EnergyDischarged bekommt Typ Zähler', 1, typ($byd->InstanceID, 'EnergyDischarged'));
pruefe('SOC bleibt Standard', 0, typ($byd->InstanceID, 'SOC'));
pruefe('kein IPS_ApplyChanges auf die Archivinstanz (Punkt 13)', [], Kernel::$applyChanges);

// -- Fall 3: Knopf an einer Instanz, deren Variablen schon bestehen ----------

echo "== Knopf EnableArchiveLogging an bestehender BYD-Instanz ==\n";
Kernel::zuruecksetzen();
$byd = new BYDCellMonitor();
$byd->Create();
$byd->ApplyChanges();                          // Variablen entstehen ohne Logging
$soh = Kernel::idFuer($byd->InstanceID, 'SOH');
Kernel::$logging[$soh] = true;                 // vom Benutzer schon geloggt
$dis = Kernel::idFuer($byd->InstanceID, 'EnergyDischarged');
Kernel::$logging[$dis] = true;                 // geloggt, aber als Standard
pruefe('Knopf ist bekannt', true, knopf($byd));
pruefe('SOC wird geloggt', true, geloggt($byd->InstanceID, 'SOC'));
pruefe('CellDelta wird geloggt', true, geloggt($byd->InstanceID, 'CellDelta'));
pruefe('SOH bleibt geloggt', true, geloggt($byd->InstanceID, 'SOH'));
pruefe('LastAlert (String) wird nicht geloggt', false, geloggt($byd->InstanceID, 'LastAlert'));
pruefe('BMSVersion (String) wird nicht geloggt', false, geloggt($byd->InstanceID, 'BMSVersion'));
pruefe('EnergyCharged bekommt Typ Zähler', 1, typ($byd->InstanceID, 'EnergyCharged'));
pruefe('EnergyDischarged bekommt Typ Zähler', 1, typ($byd->InstanceID, 'EnergyDischarged'));
pruefe('nur die schon geloggte Zählervariable wird reaggregiert', [$dis], Kernel::$reaggregiert);
pruefe('SOC bleibt Standard', 0, typ($byd->InstanceID, 'SOC'));
pruefe('kein IPS_ApplyChanges auf die Archivinstanz', [], Kernel::$applyChanges);

echo "== Knopf zweimal: nichts wird doppelt angestoßen ==\n";
Kernel::$reaggregiert = [];
knopf($byd);
pruefe('keine erneute Reaggregation', [], Kernel::$reaggregiert);

echo "== Knopf am Marstek, fremde Variablen bleiben unberührt ==\n";
Kernel::zuruecksetzen();
Kernel::$variablen[99999] = ['ident' => 'Fremd', 'typ' => VARIABLETYPE_FLOAT, 'parent' => 777];
$mst = new MarstekCellMonitor();
$mst->Create();
$mst->ApplyChanges();
knopf($mst);
pruefe('Power wird geloggt', true, geloggt($mst->InstanceID, 'Power'));
pruefe('CycleCount wird geloggt', true, geloggt($mst->InstanceID, 'CycleCount'));
pruefe('AlarmBits (String) wird nicht geloggt', false, geloggt($mst->InstanceID, 'AlarmBits'));
pruefe('Variable einer anderen Instanz bleibt ungeloggt', false, Kernel::$logging[99999] ?? false);
pruefe('am Marstek gibt es keine Zählervariable', [], array_keys(array_filter(Kernel::$aggregation)));

// -- Fall 4: ohne Archivinstanz ----------------------------------------------

echo "== Knopf ohne Archivinstanz ==\n";
Kernel::zuruecksetzen();
$byd = new BYDCellMonitor();
$byd->Create();
$byd->ApplyChanges();
Kernel::$archivVorhanden = false;
pruefe('Knopf läuft ohne Exception durch', true, knopf($byd));
pruefe('nichts wird geloggt', [], array_keys(array_filter(Kernel::$logging)));
pruefe('es gibt eine Rückmeldung an den Benutzer', true, trim(end($byd->ausgaben) ?: '') !== '');

// -- Fall 5: Formulare bieten den Knopf an, Vorgabe in form-Logik ------------

echo "== form.json beider Module ==\n";
foreach (['BYDCellMonitor', 'MarstekCellMonitor'] as $dir) {
    $form   = json_decode((string) file_get_contents(dirname(__DIR__) . "/$dir/form.json"), true, 512, JSON_THROW_ON_ERROR);
    $knopf  = array_filter($form['actions'] ?? [], static fn(array $e): bool =>
        ($e['type'] ?? '') === 'Button'
        && str_contains((string) ($e['onClick'] ?? ''), "IPS_RequestAction(\$id, 'EnableArchiveLogging', 0)"));
    pruefe("$dir: Knopf EnableArchiveLogging im Aktionsbereich", 1, count($knopf));
}

printf("\n%d Prüfungen, %d Fehler\n", $geprueft, $fehler);
exit($fehler > 0 ? 1 : 0);
