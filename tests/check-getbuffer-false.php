<?php

declare(strict_types=1);

/*
 * Regressionstest: GetBuffer() kann laut Symcon-Stub (symcon.php) string|false
 * liefern - false z. B. im kurzen Fenster, in dem die InstanceInterface einer
 * Instanz nach einem Modul-Reload noch nicht bereit ist (Status 105, siehe
 * globale CLAUDE.md, Abschnitt "Symcon: JSON-RPC-Zugriff"). Bis build 29 gab
 * das Modul dieses false ungeprüft an base64_decode() weiter.
 *
 * Gemeldet von erpe im Symcon-Forum (t/144307, PN, 19.09.2026): Fatal Error auf
 * der zweiten Turminstanz kurz nach dem Update auf Beta 1.4 #29 -
 * "Uncaught TypeError: base64_decode(): Argument #1 ($string) must be of type
 * string, false given" in rtuTransaction() (CellMonitorBase.php:565), erreicht
 * über RequestAction('TimerStatus') -> PollStatus() -> readStatusValues() ->
 * readTowerStatus() -> readWindow() -> withBusLock() -> readHoldingRegisters()
 * -> modbusTransaction() -> rtuTransaction(). Die Instanz erholte sich beim
 * nächsten Zyklus von selbst - der Fatal Error selbst war trotzdem ein Bug.
 *
 * Geprüft wird bewusst unterhalb von withBusLock()/readWindow(): die ModBus-
 * Transportebene direkt, an den drei kritischen GetBuffer-Aufrufstellen.
 * Keine Fixture nötig - simuliert wird ein Symcon-Kernel-Verhalten, kein
 * ModBus-Mitschnitt.
 *
 * Aufruf: php tests/check-getbuffer-false.php (Exit-Code 1 bei Fehlern)
 */

if (!function_exists('IPS_Sleep')) {
    function IPS_Sleep(int $ms): void {}
}

/** Minimaler Symcon-Ersatz - nur, was Create() und die ModBus-Transportebene brauchen. */
abstract class IPSModuleStrict
{
    protected array $properties = [];
    protected array $buffers    = [];

    public int $InstanceID = 12345;

    /** true = die nächste(n) Lesung(en) von 'RxBuffer' liefern false statt string. */
    public bool $rxBufferAlsFalseLiefern = false;

    public function Create(): void {}

    public function RegisterPropertyInteger(string $ident, int $vorgabe): void
    {
        $this->properties[$ident] ??= $vorgabe;
    }

    public function RegisterPropertyBoolean(string $ident, bool $vorgabe): void
    {
        $this->properties[$ident] ??= $vorgabe;
    }

    public function RegisterAttributeInteger(string $ident, int $vorgabe): void {}

    public function RegisterAttributeString(string $ident, string $vorgabe): void {}

    public function RegisterTimer(string $ident, int $intervall, string $skript): void {}

    public function ReadPropertyInteger(string $ident): int
    {
        return (int) ($this->properties[$ident] ?? 0);
    }

    public function SetBuffer(string $ident, string $wert): void
    {
        $this->buffers[$ident] = $wert;
    }

    /** Wie der echte PhpStorm-Stub: string|false. */
    public function GetBuffer(string $ident): string|false
    {
        if ($ident === 'RxBuffer' && $this->rxBufferAlsFalseLiefern) {
            return false;
        }
        return $this->buffers[$ident] ?? '';
    }

    public function SendDataToParent(string $JSONString): string
    {
        return ''; // keine Gegenstelle im Test - die Antwort bliebe ohnehin aus
    }

    public function SendDebug(string $kanal, string $text, int $format): void {}

    public function setzeProperty(string $ident, int $wert): void
    {
        $this->properties[$ident] = $wert;
    }

    public function pufferRoh(string $ident): string
    {
        return $this->buffers[$ident] ?? '';
    }
}

require_once dirname(__DIR__) . '/BYDCellMonitor/module.php';
require_once dirname(__DIR__) . '/MarstekCellMonitor/module.php';

/** Öffnet nur, was der Test braucht: readHoldingRegisters() ist protected. */
final class BydTransportHarness extends BYDCellMonitor
{
    public function leseRegisterOeffentlich(int $address, int $quantity): ?array
    {
        return $this->readHoldingRegisters($address, $quantity);
    }
}

final class MarstekTransportHarness extends MarstekCellMonitor
{
    public function leseRegisterOeffentlich(int $address, int $quantity): ?array
    {
        return $this->readHoldingRegisters($address, $quantity);
    }
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

// -- Fall 1: rtuTransaction() über BYD - die exakt gemeldete Fehlerstelle -----

echo "== rtuTransaction() bei GetBuffer('RxBuffer') === false (BYD, t/144307) ==\n";
$modul = new BydTransportHarness();
$modul->Create();
$modul->setzeProperty('TimeoutMs', 40); // kurz halten - der Test muss nicht 2 s warten
$modul->setzeProperty('UnitID', 1);
$modul->rxBufferAlsFalseLiefern = true;

$ergebnis = $modul->leseRegisterOeffentlich(0x0500, 20);
pruefe('Lesung liefert sauber null statt eines Fatal Error', null, $ergebnis);

// -- Fall 2: ReceiveData() - gemeinsamer Puffer-Anhängepfad (Zeile 453) -------

echo "== ReceiveData() bei GetBuffer('RxBuffer') === false ==\n";
$modul = new BydTransportHarness();
$modul->Create();
$modul->rxBufferAlsFalseLiefern = true;

$modul->ReceiveData(json_encode(['Buffer' => bin2hex('ab')], JSON_THROW_ON_ERROR));
pruefe('RxBuffer enthält nur den neuen Chunk statt eines Fatal Error', base64_encode('ab'), $modul->pufferRoh('RxBuffer'));

// -- Fall 3: mbapTransaction() über Marstek - dasselbe Muster, anderes Framing -

echo "== mbapTransaction() bei GetBuffer('RxBuffer') === false (Marstek) ==\n";
$modul = new MarstekTransportHarness();
$modul->Create();
$modul->setzeProperty('TimeoutMs', 40);
$modul->setzeProperty('UnitID', 1);
$modul->rxBufferAlsFalseLiefern = true;

$ergebnis = $modul->leseRegisterOeffentlich(30100, 1);
pruefe('Lesung liefert sauber null statt eines Fatal Error', null, $ergebnis);

printf("\n%d Prüfungen, %d Fehler\n", $geprueft, $fehler);
exit($fehler > 0 ? 1 : 0);
