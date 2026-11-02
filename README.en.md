# Battery Cell Monitor

[![Checks](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/BatteryCellMonitor/actions/workflows/check.yml)

Deutsche Fassung: [README.md](README.md)

Symcon module library for **monitoring the individual cell voltages** of home
storage batteries. It shows values that inverters and vendor portals keep to
themselves: single cells that run ahead at the end of charge or drop away at the
end of discharge. These are the earliest signs of ageing or defects.

| Module | Device | Status |
|---|---|---|
| **BYD Battery Cell Monitor** | BYD Battery-Box Premium (BCU, ModBus RTU over TCP, port 8080) | tested on HVM (5 modules of 16 cells) and HVS (2 towers of 4 modules of 32 cells); beta |
| **Marstek Battery Cell Monitor** | Marstek Venus E (v3 directly over the network, port 5200; older models via an RS485 bridge) | tested on a Venus E 3.0 (values checked against an independent reading); beta |

> **Beta:** The library is new and has been available in the **Module Store
> (beta channel)** since 08/2026; both modules run on real hardware. The BYD
> readout ran in production as a script for months before that. Feedback is
> welcome in the [Symcon forum](https://community.symcon.de/t/144307) (German,
> English posts are fine).

## Features

- All cell voltages per module (min/max/spread), cell delta across the tower
- Status values: SOC, current, battery voltage, SOH (BYD), temperatures, fault bits
- **Automatic measurement at the end of charge and discharge** (SOC trigger with a
  lockout time) plus a cyclic baseline measurement
- **Threshold alerts with push notifications** (tile visualization), each level
  with its own lockout time
- Archive logging on request: one button for all variables, or a checkbox that
  logs every new variable automatically
- Raw data of the last measurement as JSON if wanted (for your own analysis)

The modules talk ModBus **directly over a Client Socket**. No ModBus gateway or
device instances are needed, and the block read fix from Symcon 9.1 is not
required. The framing is set per device: BYD uses ModBus RTU over TCP (CRC16),
Marstek uses ModBus TCP (MBAP).

## Installation

From the **Module Store** (beta channel): the library is called
*Battery Cell Monitor* there, and updates arrive automatically.

Alternatively straight from Git, management console → **Modules** → Add:

```
https://github.com/bumaas/BatteryCellMonitor.git
```

## Setup

1. Create a **BYD Battery Cell Monitor** instance (or the Marstek one). The Client
   Socket is created along with it, or pick an existing one via the gear icon at
   the top of the form.
2. Enter the device address in the Client Socket:
   - BYD: IP of the BCU (Battery-Box), **port 8080**. The BCU has to be part of
     your home network for this (LAN cable in the RJ45 port of the BCU); the
     hotspot address `192.168.16.254` is only reachable from BYD's own WiFi or
     through a matching route.
   - Marstek Venus E v3: IP of the device, **port 5200** (the WiFi/LAN ModBus of
     the device; port 502 also answers as a ModBus service, but the registers
     are not reachable there). Older Venus E models via an RS485 to Ethernet
     bridge in ModBus TCP mode (e.g. Elfin EW11), port 502 there.
     **The status poll interval defaults to 25 s here and should not be raised.**
     The Venus drops the connection after 30 s without traffic. With a longer
     interval Symcon reconnects before every poll and fills the log with
     "reconnect successful".
3. For BYD choose the **BMS index (tower)** (tower 1, 2, …). For a second tower,
   create a second instance **on the same Client Socket** (BMS index 2); access
   is serialized automatically.
4. Optional: choose a visualization for push notifications, adjust the thresholds.
5. Click **"Measure cells now"**. The per-module variables are created by the
   first measurement.
6. If you want the values in the archive, click **"Enable archive logging for
   all variables"** afterwards. The button puts every numeric value of the
   instance into the archive, the two BYD energy counters with the aggregation
   type "counter" so daily and monthly values are built as differences. Variables
   that appear later (for example with more modules) are covered by the checkbox
   "Enable archive logging for new variables". Without one of the two, the module
   logs nothing.

If you currently read the BCU through ModBus gateway or device instances,
**delete them or set them inactive** first. The module reads the status values
itself, and two consumers on the same socket get in each other's way.

## Configuration (reference)

| Setting | Default | Meaning |
|---|---|---|
| BMS index (tower) (BYD only) | 1 | tower number in multi-tower systems |
| Number of modules (0 = automatic) (BYD only) | 0 | 0 = taken from the config word; better set it explicitly on HVS |
| Cells per module (BYD only) | 16 | HVM: 16, HVS: 32 |
| ModBus unit ID | 1 | unit ID in the ModBus frame |
| Timeout | 2000 ms | response timeout per request |
| Status poll interval (0 = off) | 60 s (Marstek 25 s) | short poll (SOC, current, …) |
| Cell measurement interval (0 = off) | 60 min | cyclic full measurement |
| Measure when SOC at or above / Measure when SOC at or below | 99 % / 5 % | extra measurement at the end of charge/discharge |
| Minimum gap between SOC-triggered measurements | 7200 s | lockout time for the SOC-triggered measurements |
| Thresholds | see below | warning/critical limits in mV |
| Visualization for push notifications (0 = off) | 0 | tile visualization; 0 = no push notifications |
| Warning push gap / Critical push gap | 24 h / 4 h | at most one notification per lockout time |
| Enable archive logging for new variables | off | log newly created numeric variables automatically; existing ones via the button "Enable archive logging for all variables" |
| Keep raw data of last measurement | off | last measurement as JSON in a string variable |

## Variables

Status values (depending on the device): state of charge, battery current
(negative = charging), battery voltage, SOH or power, max/min cell voltage, cell
delta, cell temperatures, fault/alarm bits, and for BYD the charged and
discharged energy counted by the BMU, the BMU temperature and the output voltage.

For BYD two master data values of the BCU sit at the top: **BMU firmware** and
**BMS firmware** (e.g. `V3.26` / `V3.31`). The module reads them on the first run
and once a day after that, so a firmware update of the battery shows up by itself.

Per cell measurement: **min/max/spread** for each module (and max temperature),
plus tower-wide "Last cell measurement", "SOC at measurement", "Current at
measurement" and "Last alert" (text of the last threshold message).

## Scripting

```php
BYDCM_Measure(12345);      // full measurement of all cells
BYDCM_PollStatus(12345);   // read the status block right away
// Marstek accordingly: MSTCM_Measure(...), MSTCM_PollStatus(...)
```

## A little battery theory

All supported batteries use **LiFePO4 cells** (LFP). Their voltage curve is the
reason this module exists: between roughly 10 and 90 % state of charge the cell
voltage sits almost unchanged at 3.2 to 3.35 V. On this plateau the voltage says
next to nothing about the condition of a single cell. Only at the ends does the
curve get steep, and that is where you see which cell keeps up and which does
not. Three things follow from this:

- **Measure at the ends.** That is why the module measures in addition to the
  cyclic measurement as soon as the SOC reaches the end of charge (99 %) or the
  end of discharge (5 %). A measurement at 60 % SOC nearly always looks clean,
  even if a cell has been weak for a long time.
- **The spread says more than the absolute value.** A cell that sits clearly
  above its neighbours at the end of charge is full sooner, so it has less
  capacity. What matters is how the spread of a module develops over weeks and
  months, not its value on a single day. That is what archive logging is for.
- **Balancing needs time at the top.** The BMS balances passively with currents
  of a few tenths of an ampere, and only at a high state of charge. A battery
  that swings between 20 and 80 % for months slowly drifts apart; an occasional
  full charge is maintenance in that sense.

Temperatures are the second early warning: cells of the same module share one
housing and should differ by only a few kelvin. A persistently larger spread
points to uneven cooling or a poor contact, and a warm contact resistance shows
up long before the voltages do.

## Thresholds (defaults)

The limits are **module defaults, not manufacturer values**. They are not read
from the BMS; they are typical LiFePO4 figures and can be set freely in each
instance.

| Threshold | Default | Idea behind it |
|---|---|---|
| Cell voltage warning (high) | 3570 mV | upper end of a normal end of charge; the curve gets steep from here |
| Cell voltage critical (high) | 3650 mV | end-of-charge voltage of the cell, where cell protection kicks in |
| Cell voltage warning (low) | 2850 mV | lower knee of the curve, well before shutdown |
| Cell voltage critical (low) | 2600 mV | deep discharge range |
| Module spread warning | 300 mV | generous, because the module measures at the end of charge |
| Temperature spread warning (0 = off) | 8 K | 2 to 5 K are usual in operation |

The BMS protects the cells itself; the alerts are meant for **early detection**,
not protection. That is why the module can warn while the vendor's app or portal
stays silent: their own protection threshold, which is higher, has not been hit.

### At the end of charge and discharge the absolute warnings pause

Exactly where the module measures, the absolute cell voltage stops describing the
cell and starts describing the operating point. At the steep end of the curve the
internal resistance alone raises the terminal voltage, and by how much depends on
the charging current at that moment: in strong sun the same healthy cell runs
higher than in weak sun. A warning on that would come back with every full charge
and turn into noise, without ever telling you anything about the cell.

So the module checks the two **warning** thresholds on the absolute value only
away from the ends. The marks are the same SOC limits that trigger the automatic
measurement (default: from 99 % and down to 5 %; a limit set to 0 switches the
exception off on its side). Not affected are:

- the **spread per module**, which at the end of the curve is the figure that
  actually matters,
- the **critical thresholds**, which mark the protection limits of the cell and
  should not be reached at the end of charge either,
- the **temperature spread**.

The debug window reports such a suppression, in German: "Kennlinienende bei SOC
99 %: 3 Absolutwert-Warnung(en) unterdrückt" (curve end at SOC 99 %: 3 absolute
warnings suppressed). That way you can see why it stayed quiet.

### What to look at instead

A single reading is no basis for judgement, but two questions about the history
are, and the logged variables answer both:

- **Does the same cell stay on top?** `CellMaxNumber` holds the number of the
  highest cell. If it moves from measurement to measurement, that is good news:
  no cell is permanently ahead. If it points to the same number for weeks, take a
  closer look at that module.
- **Is the spread growing?** What counts is not its value on one day but the
  trend over months at a comparable SOC. A cell that loses capacity runs ahead
  earlier and further at every end of charge.

For reference, the values of a healthy system (HVM, 5 modules, 60 days): the
highest cell changed between eight different numbers over 75 measurements, the
module spread varied from day to day between 157 and 271 mV without any
direction, and single cells reached up to 3646 mV at the end of charge.

## Troubleshooting

The instance status (at the top of the configuration form) names the most common
causes itself:

| Status | Meaning |
|---|---|
| No Client Socket assigned | The instance is not attached to a Client Socket. Pick or create one via the gear icon at the top. |
| Client Socket is not connected, check host and port | The socket is closed or cannot reach the device; check host and port. The instance follows the socket as soon as it is connected again. |
| Device does not respond, check port, unit ID and other ModBus clients | Three polls in a row got no answer although the socket is connected. Usually the port or the unit ID is wrong, or a second ModBus client is occupying the BCU. With the next answer the instance is back to active, and also after saving the settings or when the socket reconnects; counting then starts again. |

- **No values / timeout:** open the debug window of the instance. "Timeout - keine
  Antwort vom Gerät" (no answer from the device) with an open socket points to the
  wrong target (BCU not in the home network) or a second consumer on the same socket.
- **"BMS-Index … ist ungültig" (BMS index invalid):** the BMU does not know the
  selected tower (STATUS answer `0x4000`); check the index.
- **Max = min cell voltage, cell delta 0 mV:** not an error. These two values come
  from the status block, which has only 10 mV resolution; on a tight tower they
  coincide. The fine values per module come from a cell measurement.
- **Warning although the vendor app reports nothing:** expected. The defaults are
  deliberately below the protection thresholds of the BMS (see "Thresholds").
- **High cell voltage but no alert:** intended. At the end of charge and discharge
  the warnings on the absolute value pause, because there it is set by the charging
  current and says nothing about the cell; spread and protection limits are still
  checked (see "Thresholds"). The debug window shows how many warnings were suppressed.
- **Status values identical across several towers:** correct. SOC, SOH, current and
  the energy counters come from the BMU status block and apply to the whole
  Battery-Box; only the values from the cell measurement are per tower.
- **No "charge cycles":** the registers usually taken for that are actually energy
  counters (0.1 kWh per step); the module shows them as "Charged energy (BMU)" and
  "Discharged energy (BMU)". Equivalent full cycles are the discharged energy
  divided by the usable capacity.
- **Implausible cell values** (not around 3300 mV): for BYD, save the debug line
  **"RawWords"** of a measurement and post it in the forum; it allows working out
  the word offsets of other variants (e.g. HVS).
- **No push:** your own device has to be registered with the selected
  visualization; use the "Send test notification" button. The new Symcon app
  registers with the tile visualization, not with the old WebFront.

Log and debug messages of the module are currently German only.

## Protocol background

- BYD: handshake with a moving 65-register window after
  [sarnau/BYD-Battery-Box-Infos](https://github.com/sarnau/BYD-Battery-Box-Infos);
  the BMU accepts only single FC06 writes (no FC16), framing is ModBus RTU over TCP.
- Marstek: register map after
  [ViperRNMC/marstek_venus_modbus](https://github.com/ViperRNMC/marstek_venus_modbus)
  (Venus E v3: cell voltages from register 34018).

## Requirements

- Symcon 9.0 or later
- Battery reachable on the local network (no port forwarding to the internet!)
