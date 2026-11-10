# Loxone-Anbindung

Das Plugin liest Messwerte aus MQTT und sendet sie an PVOutput. In Loxone braucht man es nur, um **Werte aus dem Miniserver zu PVOutput zu bringen** und um das Plugin zu überwachen.

## 1. Überwachung in Loxone

**Abonnement `pvoutput/#` im MQTT Gateway:** Beim **Gateway V1** (Perl) trägt das Plugin es über die Datei `mqtt_subscriptions.cfg` selbst ein (eine Zeile je Topic). Das **Gateway V2** (Python) liest diese Datei nicht (LoxBerry-Quellcode): Dort das Abo **von Hand** im MQTT Gateway unter „Abonnements“ eintragen: `pvoutput/#`. Das Plugin prüft im Reiter *MQTT* und in der Selbstprüfung, ob es eingetragen ist.

Dann `pvoutput/status/ok` und `pvoutput/status/zaehler` im Gateway auf **No Cache** stellen.

Das Gateway ersetzt `/` durch `_`. Aus `pvoutput/status/ok` wird der Eingang `pvoutput_status_ok`:

| Eingang | Bedeutung |
|---|---|
| `pvoutput_status_ok` | 1 = Zugang vorhanden und letzter Upload erfolgreich |
| `pvoutput_status_zaehler` | Lebenszeichen 0…999, ändert sich alle 10 s |
| `pvoutput_upload_ok` | 1 = letzter Upload erfolgreich |
| `pvoutput_upload_ts` | Zeitstempel des letzten Uploads |
| `pvoutput_upload_meldung` | Antwort von PVOutput bzw. Fehlertext |
| `pvoutput_energie_erzeugung_wh` | berechnete Erzeugung seit Mitternacht (Wh) |
| `pvoutput_energie_verbrauch_wh` | berechneter Verbrauch seit Mitternacht (Wh) |

**Ausfallerkennung:** `pvoutput_status_zaehler` ändert sich alle 10 s. Bleibt er stehen, läuft der Dienst nicht mehr.

## 2. Werte aus dem Miniserver an PVOutput senden

Liegt ein Wert nur im Miniserver (Außentemperatur, Zählerstand, Netzspannung …), schickt ihn ein **virtueller Ausgang** an das MQTT-Gateway. Jedes PVOutput-Feld hat dafür einen **festen Eingang** (Quelle *Loxone (virtueller Ausgang)* in der Auswahlliste); die Tabelle samt fertigem Befehl steht im Plugin im Reiter *Einbindung in Loxone*.

1. In Loxone Config einen **virtuellen Ausgang** anlegen:

| Feld | Wert |
|---|---|
| Adresse | `/dev/udp/<LoxBerry-IP>/<UDP-Port>` |
| Befehl bei EIN | `publish <Topic aus der Tabelle> <v>` |
| Trennzeichen / HTTP | leer, **ohne** HTTP-Header/Body |

`<UDP-Port>` ist der Befehlseingang des MQTT-Gateways (UDP, Standard 11884; der Port ist einstellbar und steht im Plugin im Reiter „Einbindung in Loxone“), `<v>` ersetzt Loxone durch den Wert des angeschlossenen Ausgangs. An einen virtuellen Ausgang lassen sich mehrere Befehle hängen, einer je Wert.

2. Im Plugin beim Feld (z. B. v5 Temperatur) in der Auswahlliste *Loxone (virtueller Ausgang) → Temperatur (°C)* wählen.

| PVOutput-Feld | Eingangs-Topic | Befehl bei EIN |
|---|---|---|
| v1 Erzeugung Energie | `loxone/pvoutput/erzeugung_energie_tag_wh` (Tageswert) oder `…/erzeugung_energie_gesamt_wh` (Zählerstand) | `publish loxone/pvoutput/erzeugung_energie_tag_wh <v>` |
| v2 Erzeugung Leistung | `loxone/pvoutput/erzeugung_leistung_w` | `publish loxone/pvoutput/erzeugung_leistung_w <v>` |
| v3 Verbrauch Energie | `loxone/pvoutput/verbrauch_energie_tag_wh` oder `…/verbrauch_energie_gesamt_wh` | `publish loxone/pvoutput/verbrauch_energie_tag_wh <v>` |
| v4 Verbrauch Leistung | `loxone/pvoutput/verbrauch_leistung_w` | `publish loxone/pvoutput/verbrauch_leistung_w <v>` |
| v5 Temperatur | `loxone/pvoutput/temperatur_c` | `publish loxone/pvoutput/temperatur_c <v>` |
| v6 Spannung | `loxone/pvoutput/spannung_v` | `publish loxone/pvoutput/spannung_v <v>` |
| v7–v12 Erweitert 1–6 | `loxone/pvoutput/erweitert_1` … `erweitert_6` | `publish loxone/pvoutput/erweitert_1 <v>` |

**Format:** eine reine Zahl mit Punkt oder Komma (`21.5`, `21,5`). Eine angehängte Einheit (`21,5 °C`, `3.2 kW`) wird ignoriert, aber **nicht umgerechnet**: Liefert Loxone kW statt W, trägt man beim Feld den Faktor 1000 ein. Alles andere (Text, Datum, `12:30`) wird verworfen; ein ungültiger Wert ändert den letzten gültigen nicht.

**Regelmäßig senden:** Ein Wert gilt nur „Werte gültig für“ Sekunden (Standard 15 Minuten). Den Ausgang deshalb zyklisch senden lassen (z. B. jede Minute), am besten bei jeder Änderung *und* zyklisch.

**Tageswerte** („Energie heute“) müssen um Mitternacht auf 0 zurückgehen, wie PVOutput es erwartet. Wer nur die Leistung hat, wählt beim Feld *aus der Leistung berechnen*.

Die Eingangs-Topics liegen bewusst unter `loxone/pvoutput/…` und nicht unter `pvoutput/…`: Das Gateway-Abo `pvoutput/#` würde sie sonst als Eingänge zurück an den Miniserver spiegeln. Jedes andere Topic lässt sich weiter als *Eigenes MQTT-Topic* eintragen. Keine Leerzeichen in Topics verwenden.

**Prüfung:** Die Selbstprüfung (Reiter *Test*) sendet eine Testnachricht an die Befehlsbrücke (UDP-Port des Gateways) und zeigt, ob der Socket erreichbar ist. UDP liefert keine Antwort; ob ein Wert ankommt, zeigt *Aktuelle Werte*.
