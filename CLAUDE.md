# Lox2PVOutput – LoxBerry-Plugin

**Version: siehe `lox2pvoutput/bin/lib/AppVersion.php` (muss mit `lox2pvoutput/plugin.cfg` und [CHANGELOG.md](CHANGELOG.md) übereinstimmen; `build.sh` prüft das). Ziel-PHP: 8.4 (CLI + Web).**

Ersetzt den ioBroker-Adapter `pvoutputorg`. Liest Messwerte aus MQTT und lädt sie per `addstatus.jsp` zu pvoutput.org hoch.
Übergeordnete Regeln (Topics, Status, Versionierung): `../CLAUDE.md` und `../README.md`. Bei Widerspruch gelten die Regeln dieser Datei.

## Layout
- `lox2pvoutput/` = Inhalt des Plugin-ZIP (plugin.cfg, bin/, config/, cron/, daemon/, uninstall/, webfrontend/, *.sh)
- `../common/php/` = gemeinsamer Code (Util, Logger, MqttClient). `build.sh` kopiert ihn nach `bin/lib` des ZIP; nie aus einem anderen Plugin importieren
- `tests/` = `php tests/run.php` (Unit + Ende-zu-Ende mit Fake-Broker und Fake-PVOutput) – **nicht** im ZIP

## Harte Regeln
- Keine hartkodierten `/opt/loxberry`-Pfade. PHP: `loxberry_system.php` (`LBPCONFIGDIR`, `LBPDATADIR` …); Shell/daemon/cron: Platzhalter `REPLACELBPBINDIR` usw.
- Dateien mit LF-Zeilenenden. `.gitattributes`/`.editorconfig` beachten.
- Plugin-Identität nie ändern (`AUTHOR.NAME` + `AUTHOR.EMAIL` + `PLUGIN.NAME` + `PLUGIN.FOLDER`), sonst entsteht beim Installieren ein zweites Plugin.
- Plugin-Ordner nie fest eintragen (kann `lox2pvoutput_000` heißen): Skripte nehmen ihn aus `$3` bzw. aus ihrem Ort. MQTT-Client-ID enthält den Ordner (`pvoutput_<Ordner>`).
- Weboberfläche und Dienst: `LBWeb::lbheader()` und `mqtt_connectiondetails()` überschreiben die globale Variable `$cfg`. Eigene Konfiguration heißt deshalb `$pvo`.
- Laufzeitstatus (`status.json`, PID, Sperre) nur nach `/dev/shm/<Ordner>/`. Die Tagesenergie (`energie.json`) liegt im Datenordner und wird nur je Sendeintervall geschrieben. Konfiguration atomar schreiben (Temp + rename), Rechte 0600.
- Kein MQTT-Paket als Abhängigkeit: eigener Client (`MqttClient`). Broker-Daten aus `mqtt_connectiondetails()` oder eigenem Broker.
- **API-Key** (und Broker-Passwort) nie loggen (Logger maskiert registrierte Secrets), nie per MQTT senden, nie in der Oberfläche anzeigen, nie in die URL schreiben: PVOutput bekommt ihn nur als Header `X-Pvoutput-Apikey`. Fehlermeldungen von PVOutput werden von Tags befreit und gekürzt.
- Ohne gültigen Wert wird nichts gesendet. Werte, die älter als `max_alter` sind, zählen nicht (weder zum Senden noch zur Energieberechnung). Mindestens eines von v1–v4 muss vorliegen, sonst wird nicht gesendet.
- **Werte aus Loxone** kommen als MQTT-Nachrichten an feste Eingangs-Topics `loxone/pvoutput/<Name>` (virtueller Ausgang → UDP 11884 → Gateway → Broker); sie liegen bewusst nicht unter `pvoutput/` (kein Echo ans Abo `pvoutput/#`). Jeder Eingang gehört zu genau einem PVOutput-Feld. Die Topic-Namen nie umbenennen (Loxone-Config hängt daran). Nachrichten mit Einheit (`21,5 °C`) werden toleriert, nie umgerechnet.
- Das Plugin hat **keine Befehle** aus Loxone. Ausfall von PVOutput/Netz/Zugang: `status/ok` = 0 und `upload/ok` = 0, `status/zaehler` läuft weiter.
- Sendetakt fest zur vollen Zeit (Minute durch Intervall teilbar), nach dem Start erst zum nächsten Zeitpunkt. PVOutput erlaubt im kostenlosen Konto 60 Anfragen pro Stunde: 4xx nie wiederholen, nur Netz-/5xx-Fehler bis zu dreimal im Abstand von 60 s.
- Topic-Namen unter `pvoutput/` nie umbenennen (Loxone-Eingänge hängen daran), sonst MAJOR-Version erhöhen.

## Entwicklung
- PHP lokal nötig für die Tests; fehlt es, das offen sagen und nichts als „getestet“ melden.
- Nicht lokal testbar (nur auf dem LoxBerry bzw. mit echtem Konto): LoxBerry-Bibliotheken (`LBWeb`, `LBLog`, `mqtt_connectiondetails`), Weboberfläche, echte PVOutput-Antworten (TLS).
- Dienst lokal: `PVO_DEV_DIR=<Ordner mit pvoutput.json> PVO_DEV_MQTT=127.0.0.1:18831 PVO_DEV_URL=http://127.0.0.1:18901/service/r2/ PVO_DEV_SLOT=3 php lox2pvoutput/bin/pvoutputd.php` (Fake-Server: `php tests/fake_mqtt.php 18831 <Ordner>`, `php tests/fake_pvoutput.php 18901 <Ordner>`). `PVO_DEV_SLOT` setzt den Sendetakt in Sekunden.
- Build: `bash build.sh` → `lox2pvoutput-<version>.zip`.

## Vorgaben aus dem LoxBerry-Entwicklerwiki (Stand 2026-11-10)
- `plugin.cfg`: `CUSTOM_LOGLEVELS=true` schaltet die Loglevel-Auswahl frei (der Installer liest nur diesen Schlüssel; `LOGLEVELS_ENABLED` gibt es nicht). Version dreiteilig ohne Buchstaben, `ARCHITECTURE` mit Werten immer in Anführungszeichen, `TITLE` höchstens 25 Zeichen.
- **Gateway-Abo:** `config/mqtt_subscriptions.cfg` ist **zeilenweise** (ein Topic je Zeile, z. B. `pvoutput/#`), kein INI-Format. Nur das Gateway V1 (Perl) liest sie; das **Gateway V2 (Python) liest sie nicht** (LoxBerry-Quellcode `sbin/mqtt_gateway.py`). Die Oberfläche prüft deshalb `config/system/subscriptions.json` (`GatewayInfo::subscription`) und sagt dem Benutzer, dass er das Abo im Gateway eintragen muss. Nie behaupten, das Plugin trage das Abo selbst ein.
- **UDP-Port** des Gateways nie fest eintragen: `mqtt_connectiondetails()['udpinport']` bzw. `general.json` (`Mqtt.Udpinport`), Standard 11884. Einfache Befehlsform `publish <topic> <wert>` (Leerzeichen im Topic nur in der JSON-Form). Das Gateway hat außerdem eine HTTP-Schnittstelle (`/admin/system/tools/mqtt.php`, mit LoxBerry-Anmeldung).
- Ungenutzte Installationsskripte (`preinstall.sh` usw.) und eine leere `dpkg/apt` nicht mitliefern (verzögert die Installation).
- Healthcheck: `bin/healthcheck` (Argumente `title` und `check`, Ausgabe als JSON mit `desc`, `status`, `result`, optional `logfile`; Status 3 Fehler = Benachrichtigung und E-Mail, 4 Warnung, 5 OK, 6 Info; schnell, nur lesend, nur das JSON ausgeben, Zeitzone setzen). Ein erwarteter Zustand (z. B. Fronius nachts abgeschaltet) ist nie Fehler oder Warnung.
- Weboberfläche: Menüleiste über `$navbar` (vor `LBWeb::lbheader()` definieren), nicht über eigene Links; Tests rendern jeden Reiter mit Attrappen der LoxBerry-Bibliotheken (`tests/stubs`, `tests/ui_page.php`).
- Keine festen `/opt/loxberry`-Pfade (auch nicht in Kommentaren: der Installer warnt dann), Platzhalter `REPLACELBP…` oder Konstanten verwenden.
