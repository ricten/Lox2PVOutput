# Lox2PVOutput

![Logo](../logo/logo_loxberry.png)

**Lox2PVOutput** ist ein LoxBerry-Plugin, das Messwerte aus **MQTT-Topics** (Erzeugung, Verbrauch, Temperatur …) sammelt und in festem Takt zu **[pvoutput.org](https://pvoutput.org)** hochlädt. Es ersetzt den ioBroker-Adapter `pvoutputorg`.

Was auf dem MQTT-Broker des LoxBerry liegt, kann gesendet werden: Werte von Senec2Lox, Fronius, Shelly … und alles aus dem Miniserver, was man per MQTT-Gateway dorthin schickt.

## Was kann das Plugin?

- Sendet alle 5, 10 oder 15 Minuten (zur vollen Zeit) die PVOutput-Felder **v1–v6** und die erweiterten Felder **v7–v12**
- Bei jedem Feld wählt man in einer **Liste die Quelle** (Fronius2Lox, Senec2Lox oder ein eigenes MQTT-Topic) und den passenden Wert, dazu einen **Faktor** (z. B. 1000 bei kW → W)
- **Tagesenergie wird aus der Leistung berechnet** (Summe seit Mitternacht), wenn die Quelle keinen Tageswert liefert; alternativ Tageswert oder Gesamtzähler aus einem Topic
- Veraltete Werte werden nicht gesendet; negative Leistung wird als 0 gesendet
- Fehlerbehandlung mit Klartext von PVOutput (ungültiger Zugang, Limit erreicht …), automatische Wiederholung bei Netzfehlern
- MQTT-Broker des LoxBerry oder **eigener Broker**
- Lebenszeichen `pvoutput/status/ok|zaehler|ts` für die Ausfallerkennung in Loxone
- Weboberfläche mit Einstellungen, MQTT-Reiter, Topic-Tabelle, Zugangsprüfung ohne Schreiben, Selbstprüfung und Log

## Seiten

1. [Installation](Installation.md)
2. [Konfiguration](Konfiguration.md)
3. [MQTT-Topics](MQTT-Topics.md)
4. [Loxone-Anbindung](Loxone-Anbindung.md)
5. [Fehlersuche](Fehlersuche.md)
6. [Entwicklung](Entwicklung.md)

## Voraussetzungen

- LoxBerry ≥ 4.0 mit aktivem MQTT-Broker (Mosquitto) – oder eigener Broker
- PHP ≥ 8.4 (CLI + Web, ist bei LoxBerry 4 dabei)
- Ein Konto bei pvoutput.org mit angelegtem System (**API-Key** und **System-ID**)
- Ausgehender Zugriff auf `https://pvoutput.org` (Port 443)

## Lizenz

MIT, siehe [LICENSE](../../LICENSE). pvoutput.org ist ein Dienst von Dritten; dieses Plugin ist inoffiziell.
