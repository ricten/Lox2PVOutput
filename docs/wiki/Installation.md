# Installation

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `lox2pvoutput-<version>.zip` (enthält nur den Inhalt von `lox2pvoutput/` plus die gemeinsamen Klassen, keine Tests und keine Doku). Fertige ZIPs gibt es unter den GitHub-Releases des Repos.

## Auf dem LoxBerry installieren

1. LoxBerry-Oberfläche → **Plugin-Verwaltung** → **Plugin installieren**.
2. ZIP hochladen und installieren. Die Installation startet den Dienst.
3. Plugin **Lox2PVOutput** öffnen.
4. API-Key und System-ID eintragen, Topics prüfen, **Werte an pvoutput.org senden** anhaken, **Speichern** (siehe [Konfiguration](Konfiguration.md)).
5. Reiter **Test** → **PVOutput-Zugang prüfen**.
6. Zum nächsten vollen Intervall (z. B. :00, :05, :10 …) erscheint der erste Datensatz bei PVOutput.

## Was die Installation einrichtet

| Teil | Ort (von LoxBerry gesetzt) |
|---|---|
| Dienst und Bibliotheken | `bin/plugins/lox2pvoutput/` |
| Konfiguration (mit API-Key) | `config/plugins/lox2pvoutput/pvoutput.json` (Rechte 0600) |
| Tagesenergie aus der Leistung | `data/plugins/lox2pvoutput/energie.json` |
| Logdatei | `log/plugins/lox2pvoutput/pvoutput.log` |
| Live-Status (RAM) | `/dev/shm/lox2pvoutput/status.json` |
| Autostart | Daemon beim Boot (root → startet als `loxberry`) |
| Watchdog | Cronjob jede Minute startet den Dienst, falls er nicht läuft |

Das Plugin ändert nichts außerhalb seiner eigenen Ordner.

## Update

Beim Update sichern `preupgrade.sh`/`postupgrade.sh` **Konfiguration und Daten** und spielen sie zurück. Neue Konfigurationsschlüssel ergänzt der Dienst beim Laden automatisch mit Standardwerten.

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet und `/dev/shm/lox2pvoutput` gelöscht. Bereits bei PVOutput gespeicherte Daten bleiben dort.
