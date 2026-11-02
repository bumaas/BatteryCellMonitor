<?php

declare(strict_types=1);

/*
 * Regressionstest: die Instanz zeigt fehlende Voraussetzungen im eigenen Status.
 *
 * Anlass ist das Store-Vorab-Review vom 02.11.2026, Punkt 3 („Instanzen müssen fehlende
 * Voraussetzungen im Konfigurationsformular anzeigen“). Bis build 34 setzte das Modul nie
 * einen Status: Ein fehlender oder getrennter Client Socket und ein stummes Gerät standen nur
 * in der Debug-Ausgabe, die Instanz blieb auf 102.
 *
 * Erwartet wird jetzt:
 *  - 201, solange kein Client Socket zugewiesen ist,
 *  - 202, solange der Client Socket nicht verbunden ist (eigener Status ≠ 102),
 *  - 203, wenn drei Abfragen in Folge ohne jede Antwort des Geräts bleiben. Eine einzelne
 *    stumme Abfrage ändert nichts; an Mehrturm-Anlagen scheitert der Handshake regelmäßig.
 *  - 102, sobald wieder eine Antwort kommt; eine Teilantwort genügt.
 *  - Der Status folgt dem Socket ohne Abfrage: FM_CONNECT/FM_DISCONNECT an der eigenen
 *    Instanz, IM_CHANGESTATUS am aktuellen Parent (der alte wird abgemeldet).
 *  - Vor KR_READY wird nichts bewertet, sondern auf IPS_KERNELSTARTED gewartet.
 *  - Beide form.json führen die Codes im status-Bereich.
 *
 * Nachgezogen nach dem Code-Review vom 02.11.2026:
 *  - Befund 1: Nach ApplyChanges und nach jeder Nachricht zum Socket, bei der er verbunden
 *    ist, wird neu bewertet (203 → 102, Zähler auf 0). Bis build 37 überlebten Zähler und
 *    203 ein Wiederverbinden und das Speichern der Einstellungen.
 *  - Befund 9: Der Zähler wird nur geschrieben, wenn er sich ändert.
 *  - Befund 2: Marstek fragt ab Werk alle 25 s ab (Idle-Timeout der Venus 30 s), BYD bleibt
 *    bei 60 s.
 *
 * Die Antwort des Geräts ist der echte Statusblock-Mitschnitt aus
 * tests/fixtures/statusblock_hvm.json, als RTU-Frame verpackt (Unit 1, FC03, CRC16).
 *
 * Aufruf: php tests/check-instance-status.php (Exit-Code 1 bei Fehlern)
 */

// Echte Werte aus dem PhpStorm-Stub (symcon.php, Kernel 9.x) bzw. der Doku (Nachrichten).
foreach ([
    'VARIABLETYPE_BOOLEAN'                     => 0,
    'VARIABLETYPE_INTEGER'                     => 1,
    'VARIABLETYPE_FLOAT'                       => 2,
    'VARIABLETYPE_STRING'                      => 3,
    'IS_ACTIVE'                                => 102,
    'IS_INACTIVE'                              => 104,
    'KL_MESSAGE'                               => 10201,
    'KL_WARNING'                               => 10204,
    'KL_ERROR'                                 => 10205,
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

const INSTANZ = 12345;
const SOCKET  = 34193;
const SOCKET2 = 21399;

/** Kernelzustand der Attrappe. */
final class Kernel
{
    public static int $runlevel = KR_READY;
    /** @var array<int, array{ConnectionID: int, InstanceStatus: int}> */
    public static array $instanzen = [];

    public static function zuruecksetzen(int $parent, int $parentStatus = IS_ACTIVE): void
    {
        self::$runlevel  = KR_READY;
        self::$instanzen = [
            INSTANZ => ['ConnectionID' => $parent, 'InstanceStatus' => IS_ACTIVE],
            SOCKET  => ['ConnectionID' => 0, 'InstanceStatus' => $parentStatus],
            SOCKET2 => ['ConnectionID' => 0, 'InstanceStatus' => IS_ACTIVE],
        ];
    }
}

function IPS_GetInstance(int $id): array
{
    return Kernel::$instanzen[$id] + ['InstanceID' => $id];
}

function IPS_GetKernelRunlevel(): int
{
    return Kernel::$runlevel;
}

function IPS_GetInstanceListByModuleID(string $guid): array
{
    return [];
}

function IPS_SemaphoreEnter(string $name, int $ms): bool
{
    return true;
}

function IPS_SemaphoreLeave(string $name): bool
{
    return true;
}

function IPS_Sleep(int $ms): void {}

/** Standard-ModBus-CRC16, unabhängig vom Modul nachgerechnet. */
function crc16(string $daten): int
{
    $crc = 0xFFFF;
    foreach (str_split($daten) as $c) {
        $crc ^= ord($c);
        for ($i = 0; $i < 8; $i++) {
            $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
        }
    }
    return $crc;
}

/** Minimaler Symcon-Ersatz für Create/ApplyChanges/MessageSink und die ModBus-Strecke. */
abstract class IPSModuleStrict
{
    public int $InstanceID = INSTANZ;

    /** Antwort des Geräts je Anfrage-PDU (hex) als RTU-Frame (binär); fehlt sie, bleibt das Gerät stumm. */
    public array $antworten = [];

    protected array $properties = [];
    protected array $attributes = [];
    protected array $buffers    = [];
    /** @var array<int, list<int>> */
    protected array $messages = [];
    protected int $status     = IS_ACTIVE; // Symcon legt Instanzen aktiv an

    /** @var list<int> jede Statusänderung in Reihenfolge */
    public array $statusVerlauf = [];

    public function Create(): void {}

    public function ApplyChanges(): void {}

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void {}

    public function RegisterPropertyInteger(string $ident, int $v): void { $this->properties[$ident] ??= $v; }

    public function RegisterPropertyBoolean(string $ident, bool $v): void { $this->properties[$ident] ??= $v; }

    public function RegisterPropertyString(string $ident, string $v): void { $this->properties[$ident] ??= $v; }

    public function RegisterAttributeInteger(string $ident, int $v): void { $this->attributes[$ident] ??= $v; }

    public function RegisterAttributeString(string $ident, string $v): void { $this->attributes[$ident] ??= $v; }

    public function ReadAttributeInteger(string $ident): int { return (int) ($this->attributes[$ident] ?? 0); }

    public function ReadAttributeString(string $ident): string { return (string) ($this->attributes[$ident] ?? ''); }

    public function WriteAttributeInteger(string $ident, int $v): void { $this->attributes[$ident] = $v; }

    public function WriteAttributeString(string $ident, string $v): void { $this->attributes[$ident] = $v; }

    public function RegisterTimer(string $ident, int $intervall, string $skript): void {}

    public function SetTimerInterval(string $ident, int $intervall): void {}

    public function ReadPropertyInteger(string $ident): int { return (int) ($this->properties[$ident] ?? 0); }

    public function ReadPropertyBoolean(string $ident): bool { return (bool) ($this->properties[$ident] ?? false); }

    public function setzeProperty(string $ident, mixed $wert): void { $this->properties[$ident] = $wert; }

    public function Translate(string $text): string { return $text; }

    public function SendDebug(string $kanal, string $text, int $format): void {}

    public function LogMessage(string $text, int $typ): void {}

    public function SetValue(string $ident, mixed $wert): bool { return true; }

    public function GetIDForIdent(string $ident): int|false { return false; }

    public function RegisterVariableBoolean(string $i, string $n, array|string $p = '', int $pos = 0): void {}

    public function RegisterVariableInteger(string $i, string $n, array|string $p = '', int $pos = 0): void {}

    public function RegisterVariableFloat(string $i, string $n, array|string $p = '', int $pos = 0): void {}

    public function RegisterVariableString(string $i, string $n, array|string $p = '', int $pos = 0): void {}

    /** @var array<string, int> Schreibvorgänge je Buffer */
    public array $bufferSchreibungen = [];

    public function SetBuffer(string $ident, string $wert): void
    {
        $this->buffers[$ident] = $wert;
        $this->bufferSchreibungen[$ident] = ($this->bufferSchreibungen[$ident] ?? 0) + 1;
    }

    public function GetBuffer(string $ident): string|false { return $this->buffers[$ident] ?? ''; }

    public function HasActiveParent(): bool
    {
        $p = Kernel::$instanzen[$this->InstanceID]['ConnectionID'];
        return $p > 0 && Kernel::$instanzen[$p]['InstanceStatus'] === IS_ACTIVE;
    }

    public function SetStatus(int $status): bool
    {
        if ($status !== $this->status) {
            $this->statusVerlauf[] = $status;
        }
        $this->status = $status;
        return true;
    }

    public function GetStatus(): int { return $this->status; }

    public function RegisterMessage(int $sender, int $msg): bool
    {
        if (!in_array($msg, $this->messages[$sender] ?? [], true)) {
            $this->messages[$sender][] = $msg;
        }
        return true;
    }

    public function UnregisterMessage(int $sender, int $msg): bool
    {
        $this->messages[$sender] = array_values(array_diff($this->messages[$sender] ?? [], [$msg]));
        if ($this->messages[$sender] === []) {
            unset($this->messages[$sender]);
        }
        return true;
    }

    public function GetMessageList(): array { return $this->messages; }

    /** Gerät: antwortet synchron, wenn für die Anfrage eine Antwort hinterlegt ist. */
    public function SendDataToParent(string $JSONString): string
    {
        $frame = hex2bin(json_decode($JSONString, true)['Buffer']);
        $pdu   = bin2hex(substr($frame, 1, -2)); // RTU: Unit … CRC
        if (isset($this->antworten[$pdu])) {
            $this->ReceiveData(json_encode(['Buffer' => bin2hex($this->antworten[$pdu])]));
        }
        return '';
    }
}

require_once dirname(__DIR__) . '/BYDCellMonitor/module.php';
require_once dirname(__DIR__) . '/MarstekCellMonitor/module.php';

/** Antwort auf FC03 ab 0x0500 (20 Worte) aus dem echten HVM-Mitschnitt. */
function statusblockAntwort(): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/statusblock_hvm.json'), true, 512, JSON_THROW_ON_ERROR);
    $frame   = "\x01\x03" . chr(2 * count($fixture['worte'])) . pack('n*', ...$fixture['worte']);
    return [bin2hex("\x03\x05\x00\x00\x14") => $frame . pack('v', crc16($frame))];
}

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

function neuesBYD(int $parent, int $parentStatus = IS_ACTIVE): BYDCellMonitor
{
    Kernel::zuruecksetzen($parent, $parentStatus);
    $m = new BYDCellMonitor();
    $m->Create();
    $m->setzeProperty('TimeoutMs', 30); // Test soll nicht 2 s je stummer Anfrage warten
    return $m;
}

// -- Fall 1: Verbindung zum Client Socket ------------------------------------

echo "== Kein Client Socket zugewiesen ==\n";
$m = neuesBYD(0);
$m->ApplyChanges();
pruefe('ApplyChanges setzt 201', 201, $m->GetStatus());
pruefe('PollStatus scheitert', false, $m->PollStatus());
pruefe('Status bleibt 201', 201, $m->GetStatus());

echo "== Client Socket zugewiesen, aber nicht verbunden ==\n";
$m = neuesBYD(SOCKET, 200);
$m->ApplyChanges();
pruefe('ApplyChanges setzt 202', 202, $m->GetStatus());
pruefe('auf IM_CHANGESTATUS des Sockets angemeldet', true, in_array(IM_CHANGESTATUS, $m->GetMessageList()[SOCKET] ?? [], true));
pruefe('auf FM_CONNECT angemeldet', true, in_array(FM_CONNECT, $m->GetMessageList()[INSTANZ] ?? [], true));
pruefe('auf FM_DISCONNECT angemeldet', true, in_array(FM_DISCONNECT, $m->GetMessageList()[INSTANZ] ?? [], true));

echo "== Socket verbindet sich (IM_CHANGESTATUS) ==\n";
Kernel::$instanzen[SOCKET]['InstanceStatus'] = IS_ACTIVE;
$m->MessageSink(1, SOCKET, IM_CHANGESTATUS, [IS_ACTIVE, 0]);
pruefe('Status folgt ohne Abfrage auf 102', IS_ACTIVE, $m->GetStatus());
Kernel::$instanzen[SOCKET]['InstanceStatus'] = 200;
$m->MessageSink(2, SOCKET, IM_CHANGESTATUS, [200, 0]);
pruefe('Socket fällt aus: 202', 202, $m->GetStatus());
$m->MessageSink(3, 99999, IM_CHANGESTATUS, [IS_ACTIVE, 0]);
pruefe('fremder Absender ändert nichts', 202, $m->GetStatus());

echo "== Socket wird getrennt und ein anderer verbunden ==\n";
Kernel::$instanzen[INSTANZ]['ConnectionID'] = 0;
$m->MessageSink(4, INSTANZ, FM_DISCONNECT, [SOCKET]);
pruefe('FM_DISCONNECT: 201', 201, $m->GetStatus());
pruefe('alter Socket abgemeldet', false, isset($m->GetMessageList()[SOCKET]));
Kernel::$instanzen[INSTANZ]['ConnectionID'] = SOCKET2;
$m->MessageSink(5, INSTANZ, FM_CONNECT, [SOCKET2]);
pruefe('FM_CONNECT: 102', IS_ACTIVE, $m->GetStatus());
pruefe('neuer Socket angemeldet', true, in_array(IM_CHANGESTATUS, $m->GetMessageList()[SOCKET2] ?? [], true));

// -- Fall 2: Gerät antwortet nicht -------------------------------------------

echo "== Gerät stumm, drei Abfragen in Folge ==\n";
$m = neuesBYD(SOCKET);
$m->ApplyChanges();
$m->PollStatus();
pruefe('nach 1 stummer Abfrage noch 102', IS_ACTIVE, $m->GetStatus());
$m->PollStatus();
pruefe('nach 2 stummen Abfragen noch 102', IS_ACTIVE, $m->GetStatus());
$m->PollStatus();
pruefe('nach 3 stummen Abfragen 203', 203, $m->GetStatus());

echo "== Gerät antwortet wieder (Statusblock-Mitschnitt) ==\n";
$m->antworten = statusblockAntwort();
pruefe('PollStatus liefert Werte', true, $m->PollStatus());
pruefe('Status zurück auf 102', IS_ACTIVE, $m->GetStatus());
$m->antworten = [];
$m->PollStatus();
$m->PollStatus();
pruefe('Zähler wurde zurückgesetzt: 2 stumme Abfragen bleiben 102', IS_ACTIVE, $m->GetStatus());

echo "== Messung zählt mit ==\n";
$m->Measure();
pruefe('stumme Messung als dritte in Folge: 203', 203, $m->GetStatus());

echo "== Marstek: dieselbe Logik in der Basisklasse ==\n";
Kernel::zuruecksetzen(SOCKET);
$mst = new MarstekCellMonitor();
$mst->Create();
$mst->setzeProperty('TimeoutMs', 30);
$mst->ApplyChanges();
$mst->PollStatus();
$mst->PollStatus();
$mst->PollStatus();
pruefe('drei stumme Abfragen: 203', 203, $mst->GetStatus());
Kernel::$instanzen[SOCKET]['InstanceStatus'] = 200;
$mst->MessageSink(1, SOCKET, IM_CHANGESTATUS, [200, 0]);
pruefe('Socket fällt aus: 202 geht vor', 202, $mst->GetStatus());

// -- Fall 2b: Neubewertung nach Wiederverbinden und Speichern (Code-Review, Befund 1) --

echo "== 203, dann Socket getrennt und wieder verbunden ==\n";
$m = neuesBYD(SOCKET);
$m->ApplyChanges();
$m->PollStatus();
$m->PollStatus();
$m->PollStatus();
pruefe('Ausgang: 203', 203, $m->GetStatus());
Kernel::$instanzen[SOCKET]['InstanceStatus'] = 200;
$m->MessageSink(1, SOCKET, IM_CHANGESTATUS, [200, 0]);
Kernel::$instanzen[SOCKET]['InstanceStatus'] = IS_ACTIVE;
$m->MessageSink(2, SOCKET, IM_CHANGESTATUS, [IS_ACTIVE, 0]);
pruefe('nach dem Wiederverbinden 102', IS_ACTIVE, $m->GetStatus());
$m->PollStatus();
pruefe('eine stumme Abfrage danach bleibt 102 (Zähler neu)', IS_ACTIVE, $m->GetStatus());
$m->PollStatus();
pruefe('zwei stumme Abfragen danach bleiben 102', IS_ACTIVE, $m->GetStatus());
$m->PollStatus();
pruefe('erst die dritte setzt wieder 203', 203, $m->GetStatus());

echo "== 203, dann speichert der Anwender (ApplyChanges) ==\n";
$m = neuesBYD(SOCKET);
$m->ApplyChanges();
$m->PollStatus();
$m->PollStatus();
$m->PollStatus();
pruefe('Ausgang: 203', 203, $m->GetStatus());
$m->ApplyChanges();
pruefe('ApplyChanges bewertet neu: 102', IS_ACTIVE, $m->GetStatus());
$m->PollStatus();
pruefe('eine stumme Abfrage danach bleibt 102', IS_ACTIVE, $m->GetStatus());

echo "== 203 bleibt über weitere Abfragen stehen ==\n";
$m->PollStatus();
$m->PollStatus();
pruefe('drei stumme Abfragen: 203', 203, $m->GetStatus());
$m->PollStatus();
pruefe('die nächste stumme Abfrage hebt 203 nicht auf', 203, $m->GetStatus());

// -- Fall 2c: Zähler nur schreiben, wenn er sich ändert (Code-Review, Befund 9) --

echo "== erfolgreiche Abfragen schreiben den Zähler nicht ==\n";
$m = neuesBYD(SOCKET);
$m->ApplyChanges();
$m->antworten = statusblockAntwort();
$m->bufferSchreibungen = [];
$m->PollStatus();
$m->PollStatus();
$m->PollStatus();
pruefe('drei Antworten: SilentDialogs nie geschrieben', 0, $m->bufferSchreibungen['SilentDialogs'] ?? 0);
$m->antworten = [];
$m->PollStatus();
$m->antworten = statusblockAntwort();
$m->bufferSchreibungen = [];
$m->PollStatus();
pruefe('nach einer stummen Abfrage setzt die Antwort den Zähler einmal zurück', 1, $m->bufferSchreibungen['SilentDialogs'] ?? 0);
$m->PollStatus();
pruefe('danach keine weiteren Schreibvorgänge', 1, $m->bufferSchreibungen['SilentDialogs'] ?? 0);

// -- Fall 2d: Vorgabe des Statusintervalls (Code-Review, Befund 2) ------------

echo "== Statusintervall ab Werk ==\n";
Kernel::zuruecksetzen(SOCKET);
$mst = new MarstekCellMonitor();
$mst->Create();
pruefe('Marstek: höchstens 25 s (Idle-Timeout der Venus 30 s)', true, $mst->ReadPropertyInteger('StatusInterval') <= 25);
pruefe('Marstek: Abfrage bleibt eingeschaltet', true, $mst->ReadPropertyInteger('StatusInterval') > 0);
$m = neuesBYD(SOCKET);
pruefe('BYD: unverändert 60 s', 60, $m->ReadPropertyInteger('StatusInterval'));

// -- Fall 3: Kernelstart -----------------------------------------------------

echo "== ApplyChanges vor KR_READY ==\n";
$m = neuesBYD(0);
Kernel::$runlevel = 10102; // KR_INIT laut Doku
$m->ApplyChanges();
pruefe('vor KR_READY keine Bewertung', [], $m->statusVerlauf);
pruefe('auf IPS_KERNELSTARTED angemeldet', true, in_array(IPS_KERNELSTARTED, $m->GetMessageList()[0] ?? [], true));
Kernel::$runlevel = KR_READY;
$m->MessageSink(1, 0, IPS_KERNELSTARTED, []);
pruefe('nach IPS_KERNELSTARTED bewertet: 201', 201, $m->GetStatus());

// -- Fall 4: Formulare -------------------------------------------------------

echo "== status-Bereich beider form.json ==\n";
foreach (['BYDCellMonitor', 'MarstekCellMonitor'] as $dir) {
    $form  = json_decode((string) file_get_contents(dirname(__DIR__) . "/$dir/form.json"), true, 512, JSON_THROW_ON_ERROR);
    $codes = [];
    foreach ($form['status'] ?? [] as $s) {
        $codes[$s['code']] = $s['icon'] ?? '';
    }
    ksort($codes);
    pruefe("$dir: Codes 201-203 als Fehler", [201 => 'error', 202 => 'error', 203 => 'error'], array_intersect_key($codes, [201 => 1, 202 => 1, 203 => 1]));
}

printf("\n%d Prüfungen, %d Fehler\n", $geprueft, $fehler);
exit($fehler > 0 ? 1 : 0);
