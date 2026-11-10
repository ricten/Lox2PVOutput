# Installation

## ZIP bauen

```bash
bash build.sh
```

Ergebnis: `lox2pvoutput-<version>.zip` (enthält nur den Inhalt von `lox2pvoutput/` plus die gemeinsamen Klassen, keine Tests und keine Doku). Fertige ZIPs gibt es unter den GitHub-Releases des Repos.

**Immer das neueste Release:** Der Link `https://github.com/ricten/Lox2PVOutput/releases/latest/download/lox2pvoutput.zip` lädt das ZIP des jeweils letzten Releases (ohne Version im Dateinamen). Er lässt sich in der LoxBerry-Plugin-Verwaltung beim Installieren von einer Internetadresse eintragen und muss nach einem neuen Release nicht angepasst werden.

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

### Automatische Updates

Ab Version 0.5.0 nutzt Lox2PVOutput die Update-Funktion der LoxBerry-Plugin-Verwaltung. In der `plugin.cfg` steht der Verweis auf die Datei `release.cfg` im GitHub-Repository (`ricten/Lox2PVOutput`, Zweig `main`). LoxBerry prüft sie täglich und vergleicht die dort eingetragene Version mit der installierten.

- **Neue Installationen** stehen in der Plugin-Verwaltung bei „Automatische Updates“ auf **Releases**: eine neuere Version wird selbst heruntergeladen und installiert.
- **Schon installierte Plugins** bekommen den Verweis erst, wenn einmal von Hand (ZIP oder Link) mindestens Version 0.5.0 installiert wurde. Danach lässt sich die Einstellung in der Plugin-Verwaltung ändern: *Aus*, *Benachrichtigen* oder *Releases*.
- Konfiguration und Daten bleiben wie bei jedem Update erhalten (siehe oben).
- Die `release.cfg` zeigt immer auf das ZIP der jeweils letzten Version; sie wird erst nach dem GitHub-Release angepasst, damit nie auf ein fehlendes ZIP verwiesen wird.

## Deinstallation

Plugin in der Plugin-Verwaltung entfernen. Der Dienst wird beendet und `/dev/shm/lox2pvoutput` gelöscht. Bereits bei PVOutput gespeicherte Daten bleiben dort.
