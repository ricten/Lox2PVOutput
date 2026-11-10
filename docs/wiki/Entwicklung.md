# Entwicklung

## Aufbau

```
lox2pvoutput/               Inhalt des Plugin-ZIP
├── plugin.cfg
├── postinstall.sh preupgrade.sh postupgrade.sh
├── uninstall/uninstall  daemon/daemon  cron/cron.01min
├── bin/
│   ├── pvoutputd.php       Dienst (MQTT sammeln, Takt, Upload)
│   ├── healthcheck         LoxBerry-Healthcheck (Dienst, Broker, Quelle)
│   ├── start.sh stop.sh
│   └── lib/                 PvoConfig, PvoClient, Collector, bootstrap, AppVersion
│                            + Util, Logger, MqttClient (aus ../common/php, kopiert build.sh)
├── config/mqtt_subscriptions.cfg
├── webfrontend/htmlauth/index.php
└── icons/
tests/                       Unit-/Ende-zu-Ende-Tests und Fake-Server (nicht im ZIP)
tools/make_logo.py           Logo und Plugin-Icons (Stil wie Senec2Lox)
docs/                        Wiki, Logo (nicht im ZIP)
```

Der gemeinsame Code liegt außerhalb des Repos in `loxberry-plugins/common/php/`. `build.sh` kopiert ihn ins ZIP; kein Plugin importiert aus einem anderen.

## Klassen

| Klasse | Aufgabe |
|---|---|
| `PvoConfig` | Standardwerte, Laden, Prüfen der Eingaben, Topic-Liste |
| `Collector` | Merkt MQTT-Werte, rechnet Faktor/Alter, summiert die Tagesenergie, baut die Parameter für `addstatus.jsp` |
| `PvoClient` | HTTP-Zugriff auf die PVOutput-API (Header, Fehlertexte, Wiederholbarkeit) |

## Tests

```bash
php tests/run.php        # Unit + Ende-zu-Ende (Dienst gegen Fake-Broker und Fake-PVOutput)
```

`tests/run.php` prüft zusätzlich den **Healthcheck** (`bin/healthcheck`, alle Zustände) und rendert **jeden Reiter der Weboberfläche** mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs/`, `tests/ui_page.php`): Das findet Laufzeitfehler, prüft Menüleiste, Gateway-Abo und UDP-Port. Das echte Aussehen in LoxBerry (Design, jQuery Mobile) lässt sich so nicht prüfen. Der gemeinsame Code `GatewayInfo` hat eigene Tests: `php ../common/tests/run.php`.

`tests/run.php` startet `tests/fake_mqtt.php` und `tests/fake_pvoutput.php` selbst. Geprüft werden Konfiguration, Sammler (Faktor, Alter, Treppenfunktion, Mitternacht, Neustart, Zähler-Flags), PVOutput-Client (200, 401, 403, 500, nicht erreichbar), Retained-Nachrichten und der komplette Dienst: Abos, Upload, Lebenszeichen, Ablehnung, Wiederholung, Erholung, Konfigurationsänderung im Betrieb, Geheimhaltung des API-Keys.

**Nicht lokal testbar** (nur auf dem LoxBerry bzw. mit echtem Konto): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), die Weboberfläche, echte PVOutput-Antworten über TLS.

## Dienst lokal starten

```bash
php tests/fake_mqtt.php 18831 /tmp/pvotest &
php tests/fake_pvoutput.php 18901 /tmp/pvotest &
mkdir -p /tmp/pvotest   # pvoutput.json mit apikey, systemid, aktiv=true ablegen
PVO_DEV_DIR=/tmp/pvotest PVO_DEV_MQTT=127.0.0.1:18831 PVO_DEV_URL=http://127.0.0.1:18901/service/r2/ PVO_DEV_SLOT=3 php lox2pvoutput/bin/pvoutputd.php
```

Umgebungsvariablen im Entwicklungsmodus: `PVO_DEV_DIR` (Basisverzeichnis für Konfiguration, Log, Status), `PVO_DEV_FOLDER`, `PVO_DEV_RUN`, `PVO_DEV_MQTT=host:port`, `PVO_DEV_LOGLEVEL`, `PVO_DEV_URL` (PVOutput-Basis-URL), `PVO_DEV_SLOT` (Sendetakt in Sekunden, außerdem schnelleres Lebenszeichen).

## Build und Release

1. Version in `lox2pvoutput/plugin.cfg` **und** `lox2pvoutput/bin/lib/AppVersion.php` erhöhen (Bugfix → PATCH, Funktion → MINOR, inkompatible Topics → MAJOR).
2. Eintrag in `CHANGELOG.md` (`## [x.y.z] – Datum`).
3. `bash build.sh` prüft Versionen und Changelog, kopiert den gemeinsamen Code, prüft PHP-/Shell-Syntax und erzeugt `lox2pvoutput-<version>.zip` (alte ZIPs werden gelöscht).
4. Tag `vX.Y.Z` setzen, pushen, GitHub-Release mit dem ZIP anlegen.

## Wichtige Regeln

Siehe [CLAUDE.md](../../CLAUDE.md): keine festen Pfade (`REPLACELBPBINDIR` …), Plugin-Identität nie ändern, eigene Konfiguration nie `$cfg` nennen (LoxBerry überschreibt sie), Laufzeitstatus nur in `/dev/shm`, API-Key nie loggen und nur als Header senden.
