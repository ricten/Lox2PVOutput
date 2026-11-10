#!/bin/bash
# Baut lox2pvoutput-<version>.zip aus lox2pvoutput/ plus den gemeinsamen Klassen aus ../common/php (ohne tests/, .git, docs/).
cd "$(dirname "$0")" || exit 1
VER="$(grep '^VERSION=' lox2pvoutput/plugin.cfg | cut -d= -f2 | tr -d '\r')"
CODEVER="$(grep -o "CURRENT = '[^']*'" lox2pvoutput/bin/lib/AppVersion.php | cut -d"'" -f2)"
if [ "$VER" != "$CODEVER" ]; then
    echo "Versionen passen nicht: plugin.cfg=$VER, AppVersion.php=$CODEVER" >&2
    exit 1
fi
if ! grep -q "^## \[$VER\]" CHANGELOG.md; then
    echo "CHANGELOG.md hat keinen Eintrag fuer Version $VER" >&2
    exit 1
fi
REPO="ricten/Lox2PVOutput"
if [ "${1:-}" = "release-cfg" ]; then
    # release.cfg fuer die automatischen Updates von LoxBerry (liegt im Repo-Wurzelordner, nicht im ZIP).
    # Erst ausfuehren und committen, wenn das GitHub-Release v$VER mit dem ZIP schon existiert.
    cat > release.cfg <<EOF
[AUTOUPDATE]
# Diese Datei liest LoxBerry (RELEASECFG in plugin.cfg), wenn automatische Updates eingeschaltet sind.
# Sie wird mit "bash build.sh release-cfg" erzeugt - erst nach dem GitHub-Release, nie vorher.

# Version des neuen Releases
VERSION=$VER

# Direkter Download des ZIP (Release-Anhang auf GitHub)
ARCHIVEURL=https://github.com/$REPO/releases/download/v$VER/lox2pvoutput-$VER.zip

# Seite mit Hinweisen zu diesem Release
INFOURL=https://github.com/$REPO/releases/tag/v$VER
EOF
    sed -i 's/\r$//' release.cfg
    echo "release.cfg fuer Version $VER geschrieben"
    exit 0
fi
for c in Util Logger MqttClient GatewayInfo; do
    [ -f "../common/php/$c.php" ] || { echo "../common/php/$c.php fehlt" >&2; exit 1; }
done
PY="$(command -v python3 || command -v python)" || { echo "python noetig (ersetzt zip)" >&2; exit 1; }

# Staging: Plugin + gemeinsamer Code
STAGE="$PWD/build/stage"
rm -rf build
mkdir -p "$STAGE"
cp -r lox2pvoutput/. "$STAGE/"
cp ../common/php/Util.php ../common/php/Logger.php ../common/php/MqttClient.php ../common/php/GatewayInfo.php "$STAGE/bin/lib/"

# PHP-Syntaxpruefung, sofern php vorhanden
if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do
        php -l "$f" >/dev/null || { echo "Syntaxfehler in $f" >&2; exit 1; }
    done < <(find "$STAGE" -name '*.php')
else
    echo "Hinweis: php nicht gefunden, Syntaxpruefung uebersprungen" >&2
fi
# Healthcheck (PHP-Skript ohne Endung)
if command -v php >/dev/null 2>&1 && [ -f "$STAGE/bin/healthcheck" ]; then
    php -l "$STAGE/bin/healthcheck" >/dev/null || { echo "Syntaxfehler in bin/healthcheck" >&2; exit 1; }
fi
# Shell-Syntax
for f in "$STAGE"/*.sh "$STAGE"/bin/*.sh "$STAGE"/cron/cron.01min "$STAGE"/daemon/daemon "$STAGE"/uninstall/uninstall; do
    bash -n "$f" || { echo "Shell-Syntaxfehler in $f" >&2; exit 1; }
done

OUT="$PWD/lox2pvoutput-$VER.zip"
rm -f lox2pvoutput-*.zip      # alte Installer entfernen, nur der aktuelle bleibt
"$PY" - "$OUT" "$STAGE" <<'PYEOF'
import os, sys, zipfile
out, root = sys.argv[1], sys.argv[2]
names = ['plugin.cfg']
for d, dirs, files in os.walk(root):
    dirs.sort()
    for f in sorted(files):
        rel = os.path.relpath(os.path.join(d, f), root).replace(os.sep, '/')
        if rel != 'plugin.cfg':
            names.append(rel)
with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
    for rel in names:
        data = open(os.path.join(root, rel), 'rb').read()
        if rel.endswith(('.sh', '.php', '.cfg', '.json', 'apt', 'apt12', 'daemon', 'uninstall', 'healthcheck')) or rel.startswith('cron/'):
            data = data.replace(b'\r\n', b'\n')       # Linux: nur LF
        info = zipfile.ZipInfo(rel, (2026, 1, 1, 0, 0, 0))
        exe = rel.endswith(('.sh', '.php')) or rel in ('daemon/daemon', 'uninstall/uninstall', 'bin/healthcheck') or rel.startswith('cron/')
        info.external_attr = (0o100755 if exe else 0o100644) << 16
        info.compress_type = zipfile.ZIP_DEFLATED
        z.writestr(info, data)
PYEOF
rm -rf build
if [ -f release.cfg ]; then
    RVER="$(grep '^VERSION=' release.cfg | cut -d= -f2 | tr -d '\r')"
    [ "$RVER" = "$VER" ] || echo "Hinweis: release.cfg zeigt auf Version $RVER, plugin.cfg hat $VER (nach dem GitHub-Release: bash build.sh release-cfg)" >&2
fi
# Zusaetzlich ein ZIP ohne Version im Namen: https://github.com/ricten/<Repo>/releases/latest/download/lox2pvoutput.zip zeigt dann immer auf das neueste Release
cp -f "$OUT" "$PWD/lox2pvoutput.zip"
echo "$OUT"
echo "$PWD/lox2pvoutput.zip"
