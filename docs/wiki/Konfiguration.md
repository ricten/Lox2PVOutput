# Konfiguration

Reiter: **Einstellungen · MQTT · Einbindung in Loxone · Test · Logdateien**. Änderungen wirken ohne Neustart des Dienstes. Jeder Reiter speichert nur seine eigenen Einstellungen.

## PVOutput

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Werte an pvoutput.org senden | aus | Hauptschalter. Aus: Das Plugin sammelt Werte, sendet aber nichts |
| API-Key | – | Von pvoutput.org (*Settings → API Settings*). Wird nie angezeigt; ein leeres Feld behält den gespeicherten Key |
| System-ID | – | Nummer des Systems bei PVOutput (*Registered Systems*) |
| Sendeintervall | 5 min | 5, 10 oder 15 Minuten. Muss zum **Status Interval** der PVOutput-Systemeinstellungen passen |
| Werte gültig für | 900 s | Ein Wert, der älter ist, wird nicht gesendet und zählt bei der Energieberechnung nicht mit |

Gesendet wird zur vollen Zeit (Minute durch das Intervall teilbar). Nach dem Start des Dienstes geht der erste Upload erst zum nächsten Zeitpunkt, damit zuerst Werte gesammelt werden. Im kostenlosen PVOutput-Konto sind höchstens 60 Anfragen pro Stunde erlaubt (5 min = 12 pro Stunde).

## Messwerte aus MQTT

Für jedes PVOutput-Feld wählt man in **einer Liste** („Quelle und Wert“), woher der Wert kommt. Die Liste ist nach Quellen gegliedert und zeigt nur Werte, die zum Feld passen (zum Beispiel bei v2 nur Leistungen, bei v5 nur Temperaturen):

- **Fronius2Lox** – z. B. *PV-Leistung*, *Hausverbrauch*, *Erzeugung heute*, *Wechselrichter: AC-Spannung*, *Sensorkarte: Kanal 0*
- **Senec2Lox** – z. B. *PV-Leistung*, *Hausverbrauch*, *Batterie: höchste Temperatur*
- **Loxone (virtueller Ausgang)** – ein fester Eingang je Feld (z. B. `loxone/pvoutput/temperatur_c`), den ein virtueller Ausgang im Miniserver befüllt; Anleitung unter [Loxone-Anbindung](Loxone-Anbindung)
- **Eigenes MQTT-Topic** – jedes andere Topic (genau ein Topic, keine Platzhalter `+`/`#`), im Feld „Eigenes Topic“ einzutragen
- **nicht senden**

Das Plugin setzt das Topic der gewählten Quelle selbst zusammen und zeigt es in der Spalte „Verwendetes Topic“. Die Nachrichten müssen Zahlen sein (Punkt oder Komma als Dezimalzeichen; eine angehängte Einheit wie `21,5 °C` wird ignoriert, aber nicht umgerechnet). Dazu gibt es einen **Faktor**.

### Quellen

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Fronius2Lox: Name des Wechselrichters | `wr1` | Der Name aus den Fronius2Lox-Einstellungen; das Topic lautet `fronius/<Name>/…` |
| Senec2Lox: Topic-Präfix | `senec` | Das Topic lautet `<Präfix>/live/…` |

**Gerätenummern:** Fronius nummeriert die Geräte selbst (der Wechselrichter heißt z. B. `15` statt `1`; Topic `fronius/<Name>/wr/15/…`). Das Plugin erkennt die Nummer von Wechselrichter, Smart Meter, OhmPilot und Sensorkarte selbst aus den Topics von Fronius2Lox (es abonniert dafür `fronius/+/wr/+/leistung_ac_w` usw. und erkennt so auch den Namen), merkt sie sich (auch über die Nacht und Neustarts) und nimmt sie für alle Auswahlfelder. Taucht eine neue Nummer auf, ersetzt sie die alte. In den Quellen sind Name und Nummern Auswahllisten aus den erkannten Topics (Nummer: *automatisch* oder eine erkannte Nummer; solange nichts erkannt wurde, ein Eingabefeld). Solange nichts erkannt wurde, gelten die Standardnummern (Wechselrichter 1, Smart Meter 0, OhmPilot 0, Sensorkarte 1). Für ein weiteres Gerät das Topic als *Eigenes MQTT-Topic* eintragen.

### Felder

| Feld | PVOutput | Einheit | Standard |
|---|---|---|---|
| v1 Erzeugung Energie | Energie | Wh | aus der Leistung berechnen |
| v2 Erzeugung Leistung | Leistung | W | Senec2Lox: PV-Leistung |
| v3 Verbrauch Energie | Energie | Wh | aus der Leistung berechnen |
| v4 Verbrauch Leistung | Leistung | W | Senec2Lox: Hausverbrauch |
| v5 Temperatur | Wert | °C | nicht senden |
| v6 Spannung | Wert | V | nicht senden |
| v7–v12 Erweitert | Wert | – | nicht senden (nur mit Spenden-Konto; hier stehen alle Werte der Quellen zur Auswahl) |

Ein Feld ohne Wert wird nicht gesendet. Mindestens eines von v1–v4 muss einen Wert haben, sonst sendet das Plugin nichts (Hinweis im Log).

**Faktor:** Wert × Faktor ergibt die PVOutput-Einheit. Liefert die Quelle kW, trägt man bei Leistung `1000` ein.
Leistung und Energie werden nie negativ gesendet (negative Werte → 0). Temperatur, Spannung und erweiterte Werte bleiben unverändert.

### Betriebsart der Energiefelder (v1/v3)

Bei v1 und v3 steht die Betriebsart in derselben Liste. Bei einem Wert aus einer Quelle ergibt sie sich aus dessen Art („Tageswert“ oder „Gesamtzähler“).

| Auswahl | Bedeutung | PVOutput-Flag |
|---|---|---|
| nicht senden | Feld bleibt leer | – |
| aus der Leistung berechnen | Das Plugin summiert v2 bzw. v4 seit Mitternacht (Europe/Berlin): der letzte Leistungswert gilt bis zum nächsten. Zeiten ohne frischen Wert zählen nicht mit. Die Tagessumme übersteht Neustarts | – |
| Tageswert (Quelle, z. B. *Fronius2Lox: Erzeugung heute*) | Der Wert sind schon die Wh seit Mitternacht | – |
| Gesamtzähler (Quelle, z. B. *Fronius2Lox: Erzeugung gesamt*) | Ewig steigender Zählerstand in Wh; PVOutput bildet die Tagessumme | `c1` (1 = beide, 2 = nur v1, 3 = nur v3) |
| Eigenes Topic: Tageswert / Gesamtzähler | wie oben, mit einem Topic Ihrer Wahl | wie oben |

„Aus der Leistung berechnen“ ist genau, solange die Quelle regelmäßig meldet (Senec2Lox: bei jeder Abfrage). Ein Zähler oder Tageswert des Geräts ist genauer, wenn vorhanden: Ein **Fronius Symo (Non-Hybrid)** liefert *Erzeugung heute*; ein **GEN24** liefert keinen Tageswert, nur *Erzeugung gesamt* (alle 5 Minuten). Senec2Lox liefert keine Energiewerte, dort bleibt die Berechnung aus der Leistung.

## MQTT-Broker (Reiter MQTT)

Das **Gateway-Abo** steht als farbiges Feld oben im Reiter: **grün**, sobald mindestens ein passendes Abo im MQTT Gateway eingetragen ist (ist nur ein Teil der Topics abonniert, steht ein Hinweis dabei), **rot** nur, wenn keines eingetragen ist. Die eingetragenen Abos werden darunter aufgelistet.

| Einstellung | Standard | Beschreibung |
|---|---|---|
| Eigenen MQTT-Broker verwenden | aus | Aus: Broker des LoxBerry (Zugangsdaten verwaltet LoxBerry) |
| Host/IP, Port | – / 1883 | Eigener Broker (ohne TLS) |
| Benutzer, Passwort | leer | Optional; das Passwort wird nie angezeigt oder geloggt |

Von diesem Broker liest das Plugin die Messwerte. Retained-Nachrichten werden angenommen, damit auch Quellen, die nur bei Änderung senden, beim Start ihren letzten Wert liefern. Der Reiter zeigt außerdem Dienststatus, letzten und nächsten Upload.

## Test

| Aktion | Wirkung |
|---|---|
| PVOutput-Zugang prüfen | Liest den letzten Status von PVOutput (`getstatus.jsp`), schreibt nichts. Meldet ungültigen Key/ID |
| Aktuelle Werte | Zeigt je Feld Topic, Rohwert, Alter und den Wert, der gesendet würde |
| Selbstprüfung | Zugangsdaten, Upload an/aus, Topics, PVOutput, Broker, Gateway-Abo, Dienst, frische Werte |
| Dienst neu starten | Beendet und startet den Dienst |

Der Loglevel wird in der LoxBerry-Plugin-Verwaltung eingestellt.
