# MQTT-Topics

Das Plugin **liest** die Topics, die in den Einstellungen eingetragen sind (z. B. `senec/live/pv_w`), und **veröffentlicht** selbst nur die folgenden. Alle Werte sind reine Zahlen bzw. Text, nicht retained (außer dem Letzten Willen von `status/ok`).

| Topic | Einheit | Bedeutung |
|---|---|---|
| `pvoutput/status/ok` | 0/1 | Plugin arbeitet fehlerfrei: Zugang vorhanden und letzter Upload erfolgreich (No Cache). 0 auch, wenn der Dienst abbricht (Letzter Wille des Brokers). Bei ausgeschaltetem Upload 1 |
| `pvoutput/status/zaehler` | 0–999 | Lebenszeichen, zählt alle 10 s weiter (No Cache) |
| `pvoutput/status/ts` | s | Unix-Zeitstempel des letzten Lebenszeichens |
| `pvoutput/upload/ok` | 0/1 | Letzter Upload an PVOutput erfolgreich |
| `pvoutput/upload/ts` | s | Zeitpunkt des letzten Uploads |
| `pvoutput/upload/meldung` | Text | Antwort von PVOutput (`OK 200: Added Status`) oder Fehlertext |
| `pvoutput/energie/erzeugung_wh` | Wh | Berechnete Erzeugung seit Mitternacht (nur bei „aus der Leistung berechnen“), jede Minute |
| `pvoutput/energie/verbrauch_wh` | Wh | Berechneter Verbrauch seit Mitternacht (nur bei „aus der Leistung berechnen“), jede Minute |

Der Letzte Wille setzt `pvoutput/status/ok` auf 0 (retained), wenn der Dienst unerwartet endet.

## An PVOutput gesendete Felder (addstatus.jsp)

| Parameter | Feld | Quelle |
|---|---|---|
| `d`, `t` | Datum `JJJJMMTT`, Uhrzeit `HH:MM` | Zeitpunkt des Sendeintervalls (Europe/Berlin) |
| `v1` | Erzeugung Energie (Wh) | Betriebsart (berechnet / Tageswert / Zähler) |
| `v2` | Erzeugung Leistung (W) | Topic × Faktor |
| `v3` | Verbrauch Energie (Wh) | Betriebsart |
| `v4` | Verbrauch Leistung (W) | Topic × Faktor |
| `v5` | Temperatur (°C) | Topic × Faktor |
| `v6` | Spannung (V) | Topic × Faktor |
| `v7`–`v12` | erweiterte Werte | Topic × Faktor |
| `c1` | Gesamtzähler-Flag | nur bei Betriebsart „Gesamtzähler“ |

API-Key und System-ID gehen als HTTP-Header (`X-Pvoutput-Apikey`, `X-Pvoutput-SystemId`) hinaus, nie als Topic oder in der URL.
