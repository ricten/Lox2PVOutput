# Fehlersuche

Zuerst im Plugin den Reiter **Test** öffnen: **Selbstprüfung** und **Aktuelle Werte**. Details stehen im Reiter **Logdateien** (`pvoutput.log`); den Loglevel stellt man in der LoxBerry-Plugin-Verwaltung ein.

| Symptom | Ursache / Lösung |
|---|---|
| Nichts erscheint bei PVOutput | „Werte an pvoutput.org senden“ angehakt? API-Key und System-ID eingetragen? Nach dem Start kommt der erste Upload erst zum nächsten vollen Intervall |
| „API-Key oder System-ID ungültig (401)“ | Key oder ID falsch kopiert, oder der Key gehört zu einem anderen System. Neu eintragen (Key-Feld leer lassen behält den alten) |
| „PVOutput antwortet 403 … Exceeded number of requests“ | Stundenlimit überschritten (kostenlos: 60 pro Stunde). Intervall erhöhen oder weitere Skripte/Adapter abschalten, die dasselbe System beliefern (z. B. den ioBroker-Adapter) |
| „PVOutput antwortet 400 …“ | PVOutput lehnt die Daten ab, der Text von PVOutput nennt den Grund (z. B. ungültiger Wert oder erweiterte Felder ohne Spenden-Konto). Das Plugin wiederholt solche Ablehnungen nicht |
| „PVOutput nicht erreichbar“ | Kein Internet, DNS oder TLS am LoxBerry. Das Plugin versucht es bis zu dreimal im Abstand von 60 s, danach zum nächsten Intervall |
| „Keine aktuellen Werte (v1-v4)“ | Die Topics liefern nichts oder nur alte Werte. In **Aktuelle Werte** nach „kein frischer Wert“ suchen; Topic prüfen (Schreibweise, Groß-/Kleinschreibung), Quelle läuft? „Werte gültig für“ ggf. erhöhen |
| Feld bleibt bei „kein Wert“ | Topic falsch, Quelle sendet keine reine Zahl (z. B. Text mit Einheit) oder zu selten. Im LoxBerry-MQTT-Finder das Topic ansehen |
| Wert aus Loxone kommt nicht an | Befehl des virtuellen Ausgangs prüfen: `publish loxone/pvoutput/<Name> <v>`, Adresse `/dev/udp/<LoxBerry-IP>/<UDP-Port>`. Der Port ist einstellbar und steht im Plugin im Reiter „Einbindung in Loxone“. Den Ausgang zyklisch senden lassen. In „Aktuelle Werte“ sehen, ob der Wert ankommt (Alter in Sekunden) |
| Keine Werte aus anderen Plugins | Die Quelle muss auf dem Broker veröffentlichen. Das Abo `pvoutput/#` im Gateway betrifft nur die Status-Topics dieses Plugins, nicht das Lesen der Messwerte |
| Energie (v1) zu niedrig | Quelle meldet selten oder war zeitweise weg: Zeiten ohne frischen Wert zählen nicht mit. Besser einen Zähler des Geräts als „Tageswert“ oder „Gesamtzähler“ nutzen |
| Werte in PVOutput um Faktor 1000 daneben | Faktor setzen (kW → W: 1000; kWh → Wh: 1000) |
| Daten erscheinen zu einer anderen Zeit | Das Plugin sendet mit der Zeit des Intervalls in Europe/Berlin; die Zeitzone des PVOutput-Systems prüfen |
| `status/ok` = 0 | Zugang fehlt oder letzter Upload fehlgeschlagen (Text in `upload/meldung`). Nach dem nächsten erfolgreichen Upload wieder 1 |
| Dienst läuft nicht | Der Wächter startet ihn spätestens nach einer Minute; sonst `log/plugins/lox2pvoutput/start-error.log` ansehen oder „Dienst neu starten“ |
