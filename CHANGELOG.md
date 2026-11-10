# Changelog

Alle nennenswerten Änderungen an Lox2PVOutput. Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/) (`MAJOR.MINOR.PATCH`).

Release-Ablauf: Version in `lox2pvoutput/plugin.cfg` und `lox2pvoutput/bin/lib/AppVersion.php` erhöhen, hier einen Eintrag ergänzen,
`bash build.sh` ausführen (prüft die Übereinstimmung), danach Git-Tag `vX.Y.Z` setzen.

## [Unveröffentlicht]

## [0.4.0] – 2026-11-10

### Neu
- **Gerätenummern werden erkannt:** Fronius nummeriert Wechselrichter, Zähler usw. selbst (z. B. Wechselrichter `15` statt `1`). Die Auswahlfelder zeigen deshalb „Wechselrichter: …“ ohne feste Nummer; das Plugin liest die Nummer aus den Topics von Fronius2Lox (Wildcard-Abo `fronius/+/wr/+/leistung_ac_w` usw.), speichert sie in `entdeckt.json` (übersteht Nacht und Neustart) und baut die Topics damit. Eine neue Nummer ersetzt die alte (nur Sichtungen innerhalb einer Stunde vor der jüngsten zählen, davon die kleinste). Ohne Erkennung gelten die bisherigen Nummern (1 bzw. 0), bestehende Einstellungen bleiben gültig.
- **Auswahllisten statt Freitext:** In den Quellen wählt man Name des Wechselrichters und Gerätenummern aus den Topics, die der Dienst von Fronius2Lox gesehen hat (Nummern: „automatisch“ oder eine erkannte Nummer). Solange noch nichts erkannt wurde, bleiben es Eingabefelder. Namen werden über alle `fronius/<Name>/…` erkannt, Nummern je Name gemerkt (`entdeckt.json`). Der Status (`status.json`) enthält `nummern` und `entdeckt`.
- Behoben: Nach dem Speichern zeigte die Oberfläche „verwendet:“ leer.
- Tests: Nummernlogik, Topic-Erkennung und der Dienst (neue Nummer → neue Abos).

## [0.3.2] – 2026-11-10

Abgleich mit dem LoxBerry-Entwicklerwiki und dem LoxBerry-Quellcode.

### Behoben
- **Loglevel in der Plugin-Verwaltung:** Die Auswahl steuert in `plugin.cfg` der Schlüssel `CUSTOM_LOGLEVELS` (der Installer liest `LOGLEVELS_ENABLED` nicht). Er steht jetzt auf `true`, der überflüssige Schlüssel ist entfernt. Bei einer Aktualisierung erscheint die Auswahl, Standard ist Stufe 3.
- **Abo-Datei `mqtt_subscriptions.cfg`:** Das Gateway liest sie zeilenweise (ein Topic je Zeile), nicht als INI-Datei. Sie enthält jetzt nur `pvoutput/#`. Vorher meldete das Gateway V1 die Zeilen `[pvoutput]`, `TOPIC=…` und `NAME=…` als Abos an und `pvoutput/#` selbst nicht.
- **Gateway-Abo wird jetzt wirklich geprüft** (Reiter MQTT und Selbstprüfung). Das Gateway V2 liest die Abo-Datei von Plugins nicht (laut LoxBerry-Quellcode): Dort muss `pvoutput/#` im MQTT Gateway unter „Abonnements“ von Hand eingetragen werden. Die Prüfung liest die Abo-Liste des Gateways und erklärt, was fehlt. Vorher zeigte die Prüfung „hinterlegt“, sobald die Datei existierte.
- **UDP-Port des Gateways** wird aus den MQTT-Verbindungsdaten gelesen (`udpinport`) statt fest 11884 anzunehmen; Oberfläche, Anleitung für Loxone Config und Test der Befehlsbrücke zeigen den echten Port.

### Neu
- **Healthcheck** (`bin/healthcheck`): Die LoxBerry-Healthcheck-Seite zeigt den Zustand des Plugins (Dienst, MQTT-Broker, Quelle). Ein toter Dienst ist ein Fehler und löst die tägliche Benachrichtigung samt E-Mail aus; Ausfälle der Quelle sind eine Warnung; nicht eingerichtet ist eine Info.
- **Menüleiste** der LoxBerry-Oberfläche (`$navbar`) statt eigener Reiter.
- Neuer gemeinsamer Code `GatewayInfo` (Gateway-Version, UDP-Port, Abo-Prüfung) in `common/php`.

### Geändert
- **Gateway-Abo im Reiter MQTT als farbiges Feld:** grün, sobald mindestens ein passendes Abo im Gateway eingetragen ist (auch wenn nur ein Teil der Topics abonniert ist; dann mit Hinweis), rot nur, wenn keines eingetragen ist. Die eingetragenen Abos werden darunter aufgelistet.
- Leeres `preinstall.sh` entfernt (die Doku rät, ungenutzte Installationsskripte nicht mitzuliefern).
- Die Status-Datei enthält zusätzlich `aktiv` und `zugang` (für den Healthcheck).

## [0.3.1] – 2026-11-10

### Geändert
- Logo: Geräte, Pfeil und Name sitzen jetzt mittig im Kreis (vorher zu hoch). `tools/make_logo.py` berechnet die Verschiebung aus den Umrissen aller Elemente, statt sie von Hand einzustellen.

## [0.3.0] – 2026-11-10

### Neu
- **Werte aus Loxone:** Neue Quelle *Loxone (virtueller Ausgang)* in der Auswahlliste. Jedes PVOutput-Feld hat einen festen Eingang `loxone/pvoutput/<Name>` (z. B. `temperatur_c`, `erzeugung_leistung_w`, `verbrauch_energie_tag_wh`, `erweitert_1` … `erweitert_6`), den ein virtueller Ausgang im Miniserver per UDP-Befehl (`publish <Topic> <v>`) befüllt. Der Reiter *Einbindung in Loxone* zeigt die Einträge für Loxone Config samt fertigem Befehl und der LoxBerry-IP.
- **Format der Werte:** Komma oder Punkt als Dezimalzeichen; eine angehängte Einheit (`21,5 °C`, `3.2 kW`) wird ignoriert, aber nicht umgerechnet (dafür der Faktor). Text, Datum und ähnliches werden verworfen, ein ungültiger Wert ändert den letzten gültigen nicht.
- Selbstprüfung: Test der Befehlsbrücke (UDP 11884) für Werte aus Loxone.

### Geändert
- Die Loxone-Eingänge liegen unter `loxone/pvoutput/…` und nicht unter `pvoutput/…`, damit das Gateway-Abo `pvoutput/#` sie nicht an den Miniserver zurückspiegelt.

## [0.2.0] – 2026-11-09

### Neu
- **Auswahlliste je Feld:** Statt ein Topic von Hand einzutragen, wählt man bei jedem PVOutput-Feld in einer Liste, **woher der Wert kommt**: Quelle **Fronius2Lox** oder **Senec2Lox** mit einem ihrer Werte (z. B. *Fronius2Lox → PV-Leistung*), ein eigenes MQTT-Topic oder „nicht senden“. Die Liste zeigt nur Werte, die zum Feld passen (v2/v4 Leistungen, v5 Temperaturen, v6 Spannungen, v1/v3 Tageswerte und Gesamtzähler; v7–v12 alle). Das Plugin setzt das Topic selbst zusammen und zeigt es an.
- Bei den Energiefeldern v1/v3 steht die Betriebsart (aus der Leistung berechnen, nicht senden, Tageswert, Gesamtzähler) in derselben Liste und ergibt sich bei Quellwerten aus deren Art.
- Einstellungen für die Quellen: Name des Wechselrichters bei Fronius2Lox (Standard `wr1`) und Topic-Präfix bei Senec2Lox (Standard `senec`).
- Katalog mit den Werten beider Plugins (Fronius2Lox einschließlich *Erzeugung heute*/*gesamt*, Sensorkarte, OhmPilot, Smart Meter; Senec2Lox einschließlich Wallbox, Batterietemperaturen).

### Geändert
- Konfiguration je Feld: `quelle`, `wert`, `eigenes` (eigenes Topic), `faktor`, `modus`. Ältere Konfigurationen mit nur einem Topic je Feld werden als „eigenes Topic“ übernommen.

## [0.1.0] – 2026-11-09

Erste Version. Ersetzt den ioBroker-Adapter `pvoutputorg`.

### Neu
- Liest Messwerte aus **MQTT-Topics** (frei wählbar je PVOutput-Feld, mit Faktor) und sendet sie im festen Takt (5, 10 oder 15 Minuten, zur vollen Zeit) per `addstatus.jsp` an **pvoutput.org**.
- Felder: Erzeugung Energie/Leistung (v1/v2), Verbrauch Energie/Leistung (v3/v4), Temperatur (v5), Spannung (v6), erweiterte Werte v7–v12.
- **Tagesenergie aus der Leistung**: v1/v3 lassen sich aus v2/v4 berechnen (Summe seit Mitternacht, Europe/Berlin, übersteht Neustarts). Alternativ Tageswert oder Gesamtzähler (Flag `c1`) aus einem Topic.
- Werte gelten nur begrenzt lang (Standard 15 Minuten); veraltete Werte werden nicht gesendet und zählen bei der Energieberechnung nicht mit. Negative Leistung/Energie wird als 0 gesendet.
- Retained-Nachrichten werden angenommen (Quellen, die nur bei Änderung senden).
- Fehlerbehandlung: ungültiger Zugang (401), Limit (403) und sonstige Ablehnungen werden mit PVOutput-Text gemeldet; bei Netz- und Serverfehlern bis zu drei Versuche im Abstand von 60 s. `status/ok` = 0, Lebenszeichen läuft weiter.
- MQTT-Broker des LoxBerry oder **eigener Broker**.
- Topics: `pvoutput/status/ok|zaehler|ts`, `pvoutput/upload/ok|ts|meldung`, `pvoutput/energie/erzeugung_wh|verbrauch_wh`.
- Weboberfläche: Einstellungen, MQTT, Einbindung in Loxone, Test (Zugang prüfen ohne Schreiben, aktuelle Werte, Selbstprüfung), Logdateien.
- Vorgabe der Topics passend zu Senec2Lox (`senec/live/pv_w`, `senec/live/haus_w`).

### Intern
- Gemeinsamer MQTT-Client (`common/php/MqttClient.php`): neue Option `acceptRetained()`; Standard unverändert (retained wird ignoriert).
