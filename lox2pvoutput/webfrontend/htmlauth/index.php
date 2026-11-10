<?php
// Weboberflaeche Lox2PVOutput: Einstellungen · MQTT · Einbindung in Loxone · Test · Logdateien
// Achtung: LBWeb::lbheader() und mqtt_connectiondetails() ueberschreiben die globale Variable $cfg -> eigene Konfiguration heisst $pvo.

require_once 'loxberry_system.php';
require_once 'loxberry_web.php';
require_once 'loxberry_io.php';
require_once LBPBINDIR . '/lib/bootstrap.php';

session_start();
if (empty($_SESSION['pvo_token'])) {
    $_SESSION['pvo_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['pvo_token'];

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$paths = Paths::detect();
$entdeckt = Util::jsonLoad($paths->entdeckt);
$pvo = PvoConfig::mitEntdeckten(PvoConfig::load($paths->config), $entdeckt);
$tab = $_GET['tab'] ?? 'einstellungen';
if (!in_array($tab, ['einstellungen', 'mqtt', 'loxone', 'test', 'logs'], true)) {
    $tab = 'einstellungen';
}
$meldungen = [];

// ------------------------------------------------------------ Aktionen
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($token, (string)($_POST['token'] ?? ''))) {
        http_response_code(403);
        exit('Ungueltiges Formular-Token, Seite neu laden.');
    }
    $aktion = (string)($_POST['aktion'] ?? '');
    if ($aktion === 'speichern') {
        $neu = PvoConfig::fromPost($pvo, $_POST);
        if (Util::jsonSave($paths->config, $neu)) {
            $meldungen[] = 'Einstellungen gespeichert.';
        } else {
            $meldungen[] = 'FEHLER: Einstellungen konnten nicht gespeichert werden.';
        }
        $pvo = PvoConfig::mitEntdeckten($neu, $entdeckt);
    } elseif ($aktion === 'neustart') {
        exec(escapeshellarg(LBPBINDIR . '/stop.sh') . ' 2>&1');
        exec(escapeshellarg(LBPBINDIR . '/start.sh') . ' 2>&1');
        $meldungen[] = 'Dienst neu gestartet.';
    }
}

$status = Util::jsonLoad($paths->statusFile());
$alter = isset($status['time']) ? time() - (int)$status['time'] : null;
$laeuft = $alter !== null && $alter <= 10;
$basis = '?tab=';
$felder = (array)($status['felder'] ?? []);

$tabs = ['einstellungen' => 'Einstellungen', 'mqtt' => 'MQTT', 'loxone' => 'Einbindung in Loxone', 'test' => 'Test', 'logs' => 'Logdateien'];
// Menüleiste der LoxBerry-Oberfläche: $navbar muss vor lbheader() definiert sein
$navbar = [];
$nr = 10;
foreach ($tabs as $k => $t) {
    $navbar[$nr] = ['Name' => $t, 'URL' => $basis . $k] + ($tab === $k ? ['active' => true] : []);
    $nr += 10;
}
// UDP-Port des Gateways für Befehle aus Loxone (einstellbar, steht in den MQTT-Verbindungsdaten)
$udp = GatewayInfo::udpPort(function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : null, GatewayInfo::general());

LBWeb::lbheader('Lox2PVOutput ' . AppVersion::CURRENT, '', '');
?>
<style>
 .pvo table { border-collapse:collapse; margin:8px 0 16px 0; }
 .pvo th, .pvo td { border:1px solid #bbb; padding:4px 8px; text-align:left; vertical-align:top; }
 .pvo pre { background:#f4f4f4; color:#222; padding:8px; overflow:auto; max-height:420px; }
 .pvo .ok { color:#2a8a2a; font-weight:bold; } .pvo .fehler { color:#c0392b; font-weight:bold; }
 .pvo .hinweis { color:#666; font-size:90%; }
 .pvo input.pvo-chk { display:inline-block; position:static; opacity:1; width:22px; height:22px; margin:4px; }
</style>
<div class="pvo">
<?php foreach ($meldungen as $m) { echo '<p class="' . (str_starts_with($m, 'FEHLER') ? 'fehler' : 'ok') . '">' . h($m) . '</p>'; } ?>

<?php if ($tab === 'einstellungen') { ?>
<form method="post" action="<?= $basis ?>einstellungen" data-ajax="false">
<input type="hidden" name="token" value="<?= h($token) ?>">

<h3>PVOutput</h3>
<p><label><input type="checkbox" data-role="none" class="pvo-chk" name="aktiv" value="1" <?= $pvo['aktiv'] ? 'checked' : '' ?>>
 Werte an pvoutput.org senden</label></p>
<table>
<tr><th>API-Key</th><td><input type="password" name="apikey" value="" size="44" autocomplete="new-password" placeholder="<?= $pvo['apikey'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
<tr><th>System-ID</th><td><input type="text" name="systemid" value="<?= h($pvo['systemid']) ?>" size="10" inputmode="numeric"></td></tr>
<tr><th>Sendeintervall</th><td><select name="intervall">
<?php foreach (PvoConfig::INTERVALLE as $iv) { echo '<option value="' . $iv . '"' . ($pvo['intervall'] === $iv ? ' selected' : '') . '>alle ' . $iv . ' Minuten</option>'; } ?>
</select></td></tr>
<tr><th>Werte gültig für</th><td><input type="number" name="max_alter" min="60" max="86400" value="<?= (int)$pvo['max_alter'] ?>" style="width:90px"> Sekunden</td></tr>
</table>
<p class="hinweis">API-Key und System-ID stehen bei pvoutput.org unter <i>Settings → Registered Systems</i> bzw. <i>API Settings</i>.
Das Intervall muss zum <i>Status Interval</i> der PVOutput-Systemeinstellungen passen. Kostenlose Konten dürfen höchstens 60 Anfragen pro Stunde senden.
Ein leeres API-Key-Feld behält den gespeicherten Key. „Werte gültig für“: ein Wert, der älter ist, wird nicht mehr gesendet (und zählt bei der Energieberechnung nicht mit).</p>

<h3>Quellen</h3>
<table>
<?php
// Auswahllisten aus den Topics, die der Dienst von Fronius2Lox gesehen hat (fronius/<Name>/<Art>/<Nr>/...); ohne Funde ein Eingabefeld
$namen = array_keys((array)($entdeckt['namen'] ?? []));
sort($namen, SORT_STRING);
$aktName = (string)$pvo['quellen']['fronius_name'];
$namenListe = array_values(array_unique(array_merge($namen, [$aktName])));
if ($namen) { ?>
<tr><th>Fronius2Lox: Name des Wechselrichters</th><td><select name="quellen[fronius_name]">
<?php foreach ($namenListe as $n) { echo '<option value="' . h($n) . '"' . ($n === $aktName ? ' selected' : '') . '>' . h($n) . (in_array($n, $namen, true) ? '' : ' (nicht erkannt)') . '</option>'; } ?>
</select> <span class="hinweis">Auswahl aus den erkannten Topics <code>fronius/&lt;Name&gt;/…</code></span></td></tr>
<?php } else { ?>
<tr><th>Fronius2Lox: Name des Wechselrichters</th><td><input type="text" name="quellen[fronius_name]" value="<?= h($aktName) ?>" size="12"> <span class="hinweis">Topic <code>fronius/&lt;Name&gt;/…</code> (Standard wr1); noch keine Topics von Fronius2Lox gesehen, sonst gäbe es hier eine Auswahl</span></td></tr>
<?php }
foreach (PvoConfig::NUMMERN as $typ => [$bez, $std]) {
    $gefunden = array_keys((array)($entdeckt['geraete'][$aktName][$typ] ?? []));
    sort($gefunden, SORT_NUMERIC);
    $hand = (string)$pvo['quellen']['fronius_' . $typ];
    $verw = (string)$pvo['quellen']['nr'][$typ]; ?>
<tr><th>Fronius2Lox: Nummer <?= h($bez) ?></th><td>
<?php if ($gefunden) { ?>
<select name="quellen[fronius_<?= h($typ) ?>]">
<option value=""<?= $hand === '' ? ' selected' : '' ?>>automatisch (aktuell <?= h($verw) ?>)</option>
<?php foreach (array_values(array_unique(array_merge($gefunden, $hand !== '' ? [$hand] : []))) as $n) {
    echo '<option value="' . h($n) . '"' . ($hand === (string)$n ? ' selected' : '') . '>' . h($n) . (in_array((string)$n, array_map('strval', $gefunden), true) ? '' : ' (nicht erkannt)') . '</option>';
} ?>
</select> <span class="hinweis">erkannt in <code>fronius/<?= h($aktName) ?>/<?= h($typ) ?>/&lt;Nr&gt;/…</code>; verwendet: <b><?= h($verw) ?></b></span>
<?php } else { ?>
<input type="text" name="quellen[fronius_<?= h($typ) ?>]" value="<?= h($hand) ?>" size="6" placeholder="automatisch">
 <span class="hinweis">leer = automatisch; noch nichts erkannt in <code>fronius/<?= h($aktName) ?>/<?= h($typ) ?>/&lt;Nr&gt;/…</code>, verwendet: <b><?= h($verw) ?></b> (Standard <?= h($std) ?>)</span>
<?php } ?>
</td></tr>
<?php } ?>
<tr><th>Senec2Lox: Topic-Präfix</th><td><input type="text" name="quellen[senec_praefix]" value="<?= h($pvo['quellen']['senec_praefix']) ?>" size="12"> <span class="hinweis">Topic <code>&lt;Präfix&gt;/live/…</code> (Standard senec)</span></td></tr>
</table>

<h3>Woher kommen die Werte?</h3>
<table>
<tr><th>Feld</th><th>Quelle und Wert</th><th>Eigenes Topic</th><th>Faktor</th><th>Verwendetes Topic</th><th>Aktuell</th></tr>
<?php foreach (PvoConfig::FELDER as $k => [$name, $einheit, $art]) {
    $f = $pvo['felder'][$k];
    $live = $felder[$k] ?? null;
    $sel = PvoConfig::auswahlWert($f);
    ?>
<tr>
 <td><b><?= h($k) ?></b> <?= h($name) ?><?= $einheit !== '' ? ' <span class="hinweis">(' . h($einheit) . ')</span>' : '' ?></td>
 <td><select name="felder[<?= $k ?>][auswahl]">
<?php foreach (PvoConfig::auswahl($k) as $gruppe => $eintraege) {
    echo '<optgroup label="' . h($gruppe) . '">';
    foreach ($eintraege as $key => $label) {
        echo '<option value="' . h($key) . '"' . ($sel === $key ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</optgroup>';
} ?>
 </select></td>
 <td><input type="text" name="felder[<?= $k ?>][eigenes]" value="<?= h($f['eigenes']) ?>" size="26" placeholder="nur bei „Eigenes Topic“"></td>
 <td><input type="text" name="felder[<?= $k ?>][faktor]" value="<?= h(rtrim(rtrim(number_format((float)$f['faktor'], 6, '.', ''), '0'), '.')) ?>" size="6"></td>
 <td><?php
    if (($f['modus'] ?? '') === 'integral') {
        echo '<span class="hinweis">berechnet aus ' . h($pvo['felder'][$k === 'v1' ? 'v2' : 'v4']['topic'] ?: 'der Leistung (kein Topic gewählt)') . '</span>';
    } else {
        echo $f['topic'] !== '' ? '<code>' . h($f['topic']) . '</code>' : '<span class="hinweis">nicht gesendet</span>';
    } ?></td>
 <td><?php if ($live && $live['wert'] !== null) { echo h(round((float)$live['wert'], 2)) . ' ' . h($einheit); } elseif ($live && !empty($live['aktiv'])) { echo '<span class="fehler">kein Wert</span>'; } else { echo '-'; } ?></td>
</tr>
<?php } ?>
</table>
<p class="hinweis">
<b>Quelle und Wert</b>: Aus der Liste wählen, welches Plugin den Wert liefert (z. B. <i>Fronius2Lox → PV-Leistung</i>). Das Plugin setzt das Topic selbst zusammen und zeigt es in der Spalte „Verwendetes Topic“.
Mit <i>Eigenes MQTT-Topic</i> lässt sich jedes andere Topic eintragen (genau ein Topic ohne Platzhalter, nur reine Zahlen), z. B. <code>loxone/aussentemperatur</code>.
Bei einer Quelle gelten die Nummern des ersten Geräts (<code>wr/1</code>, <code>zaehler/0</code>); für weitere Geräte ein eigenes Topic verwenden.<br>
<b>Faktor</b>: Wert × Faktor ergibt die PVOutput-Einheit (z. B. 1000 bei kW → W, 1000 bei kWh → Wh). Leistung (W) und Energie (Wh) werden nie negativ gesendet.<br>
<b>Erzeugung/Verbrauch Energie (v1/v3)</b>: „aus der Leistung berechnen“ summiert v2 bzw. v4 seit Mitternacht (Europe/Berlin) und braucht kein eigenes Topic.
Ein <i>Tageswert</i> (Wh seit Mitternacht) wird direkt gesendet; ein <i>Gesamtzähler</i> (Wh, ewig steigend) ebenfalls, PVOutput bildet dann die Tagessumme selbst. Die Betriebsart ergibt sich aus der Auswahl.
Ein GEN24 liefert keinen Tageswert; ein Symo (Non-Hybrid) liefert <i>Erzeugung heute</i>, das ist meist die genaueste Wahl.<br>
<b>v7–v12</b> (erweiterte Werte) nimmt PVOutput nur mit Spenden-Konto an; hier stehen alle Werte der Quellen zur Auswahl.</p>
<p><button type="submit" name="aktion" value="speichern" data-ajax="false">Speichern</button></p>
</form>

<?php } elseif ($tab === 'mqtt') {
    $own = PvoConfig::ownBroker($pvo);
    $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
    $gw = GatewayInfo::subscription('pvoutput/#', GatewayInfo::general());
    $up = (array)($status['upload'] ?? []);
    ?>
<h3>MQTT</h3>
<table>
<tr><th><?= $own !== null ? 'Broker (eigener, aus den Plugin-Einstellungen)' : 'Broker (aus der LoxBerry-Konfiguration)' ?></th><td><?= h(($d['brokerhost'] ?? '?') . ':' . ($d['brokerport'] ?? '?')) ?></td></tr>
<tr><th>Gateway-Abo</th><td><?= GatewayInfo::feldHtml($gw, 'pvoutput/#') ?></td></tr>
<tr><th>Dienst</th><td><?= $laeuft ? '<span class="ok">läuft</span> (Version ' . h($status['version'] ?? '?') . ')' : '<span class="fehler">läuft nicht</span> (der Wächter startet ihn spätestens nach einer Minute)' ?></td></tr>
<tr><th>Broker-Verbindung des Dienstes</th><td><?= !empty($status['mqtt']) ? '<span class="ok">verbunden</span>' : '<span class="fehler">getrennt</span> ' . h($status['mqtt_error'] ?? '') ?></td></tr>
<tr><th>Letzter Upload</th><td><?php
    if (empty($up['ts'])) {
        echo '-';
    } else {
        echo h(date('d.m.Y H:i:s', (int)$up['ts'])) . ' ' . (!empty($up['ok']) ? '<span class="ok">erfolgreich</span>' : '<span class="fehler">Fehler</span>') . ' – ' . h($up['meldung'] ?? '');
    }
    ?></td></tr>
<tr><th>Nächster Upload</th><td><?= !empty($status['naechster']) ? h(date('H:i:s', (int)$status['naechster'])) : '-' ?></td></tr>
<tr><th>status/ok</th><td><?= isset($status['ok']) ? (int)$status['ok'] : '-' ?></td></tr>
<tr><th>status/zaehler</th><td><?= isset($status['zaehler']) ? (int)$status['zaehler'] : '-' ?></td></tr>
<tr><th>status/ts</th><td><?= !empty($status['ts']) ? h(date('d.m.Y H:i:s', (int)$status['ts'])) : '-' ?></td></tr>
</table>
<form method="post" action="<?= $basis ?>mqtt" data-ajax="false">
<input type="hidden" name="token" value="<?= h($token) ?>">
<h3>MQTT-Broker</h3>
<?php $mq = $pvo['mqtt']; ?>
<p><label><input type="checkbox" name="mqtt[eigener_broker]" value="1" <?= !empty($mq['eigener_broker']) ? 'checked' : '' ?>>
 Eigenen MQTT-Broker verwenden (statt dem Broker des LoxBerry)</label></p>
<table>
<tr><th>Host/IP</th><td><input type="text" name="mqtt[host]" value="<?= h($mq['host']) ?>" size="24"></td>
<th>Port</th><td><input type="number" name="mqtt[port]" value="<?= (int)$mq['port'] ?>" style="width:90px"></td></tr>
<tr><th>Benutzer</th><td><input type="text" name="mqtt[benutzer]" value="<?= h($mq['benutzer']) ?>" size="16" autocomplete="off"></td>
<th>Passwort</th><td><input type="password" name="mqtt[passwort]" value="" size="16" autocomplete="new-password" placeholder="<?= $mq['passwort'] !== '' ? '(gesetzt)' : '' ?>"></td></tr>
</table>
<p class="hinweis">Von diesem Broker liest das Plugin die Messwerte und veröffentlicht darüber seine Status-Topics. Ohne Haken gilt der Broker aus der LoxBerry-Konfiguration (empfohlen, Zugangsdaten verwaltet LoxBerry).
Mit eigenem Broker erreichen die Status-Topics den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert. Ein leeres Passwortfeld behält das gespeicherte Passwort.</p>
<p><button type="submit" name="aktion" value="speichern" data-ajax="false">Speichern</button></p>
</form>

<?php } elseif ($tab === 'loxone') {
    $werte = [
        ['pvoutput/status/ok', '0/1', 'Plugin arbeitet fehlerfrei: Upload zuletzt erfolgreich, Zugang vorhanden (No Cache)'],
        ['pvoutput/status/zaehler', '0–999', 'Lebenszeichen, zählt alle 10 s weiter (No Cache)'],
        ['pvoutput/status/ts', 's', 'Unix-Zeitstempel des letzten Lebenszeichens'],
        ['pvoutput/upload/ok', '0/1', 'Letzter Upload an PVOutput erfolgreich'],
        ['pvoutput/upload/ts', 's', 'Unix-Zeitstempel des letzten Uploads'],
        ['pvoutput/upload/meldung', 'Text', 'Antwort von PVOutput bzw. Fehlertext'],
        ['pvoutput/energie/erzeugung_wh', 'Wh', 'Berechnete Erzeugung seit Mitternacht (nur bei „aus der Leistung berechnen“)'],
        ['pvoutput/energie/verbrauch_wh', 'Wh', 'Berechneter Verbrauch seit Mitternacht (nur bei „aus der Leistung berechnen“)'],
    ];
    ?>
<h3>Einbindung in Loxone</h3>
<p>Dieses Plugin <b>liest</b> Messwerte aus MQTT und sendet sie an pvoutput.org. Für Loxone liefert es nur Status und die berechnete Tagesenergie.
Der Eingangsname ist das Topic mit _ statt /. Die Werte kommen über das MQTT-Gateway (Abo pvoutput/#) als virtuelle Eingänge.</p>
<table>
<tr><th>Topic</th><th>Eingangsname</th><th>Einheit</th><th>Bedeutung</th><th>retained</th></tr>
<?php foreach ($werte as $w) {
    echo '<tr><td>' . h($w[0]) . '</td><td>' . h(str_replace('/', '_', $w[0])) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
} ?>
</table>
<p class="hinweis">Beim Absturz des Dienstes setzt der Broker pvoutput/status/ok per Letzter-Wille-Nachricht auf 0 (retained).</p>

<h3>Werte aus Loxone an PVOutput senden</h3>
<?php $lbip = (string)($_SERVER['SERVER_ADDR'] ?? ''); $lbip = ($lbip === '' || $lbip === '::1') ? '<LoxBerry-IP>' : $lbip; ?>
<p>Werte, die nur im Miniserver stehen (z. B. Außentemperatur, Zählerstand, Netzspannung), schickt ein <b>virtueller Ausgang</b> an das MQTT-Gateway. Jedes PVOutput-Feld hat dafür einen <b>festen Eingang</b>.
Im Reiter <i>Einstellungen</i> wählt man beim Feld dann in der Liste <i>Loxone (virtueller Ausgang)</i>.</p>
<table>
<tr><th>Eintrag in Loxone Config</th><th>Wert</th></tr>
<tr><td>Virtueller Ausgang, Adresse</td><td><code>/dev/udp/<?= h($lbip) ?>/<?= (int)$udp ?></code></td></tr>
<tr><td>Befehl bei EIN</td><td><code>publish &lt;Topic aus der Tabelle&gt; &lt;v&gt;</code></td></tr>
<tr><td>Trennzeichen / HTTP</td><td>leer, <b>ohne</b> HTTP-Header/Body</td></tr>
</table>
<table>
<tr><th>PVOutput-Feld</th><th>Wert</th><th>Befehl bei EIN (Loxone)</th></tr>
<?php foreach (PvoConfig::loxoneEingaenge() as $e) {
    $f = PvoConfig::FELDER[$e['feld']];
    echo '<tr><td><b>' . h($e['feld']) . '</b> ' . h($f[0]) . '</td><td>' . h($e['bezeichnung']) . ($e['art'] === 'zaehler' ? ' – Zählerstand, steigt ständig' : '') . '</td><td><code>publish ' . h($e['topic']) . ' &lt;v&gt;</code></td></tr>';
} ?>
</table>
<p class="hinweis">
<b>Format:</b> eine reine Zahl, mit Punkt oder Komma (<code>21.5</code> oder <code>21,5</code>). Eine angehängte Einheit wie <code>21,5 °C</code> wird ignoriert, aber nicht umgerechnet; liefert Loxone kW statt W, trägt man den Faktor 1000 ein. <code>&lt;v&gt;</code> ersetzt Loxone durch den Wert des angeschlossenen Ausgangs.<br>
<b>Pro Wert ein Befehl:</b> An einen virtuellen Ausgang lassen sich mehrere Befehle hängen, jeder an seinem Analogwert. Wer mehrere Felder schickt, legt mehrere Befehle an.<br>
<b>Regelmäßig senden:</b> Der Wert gilt nur „Werte gültig für“ Sekunden (Einstellungen, Standard 15 Minuten). Den Ausgang deshalb zyklisch senden lassen (z. B. jede Minute) oder bei jeder Änderung <i>und</i> zyklisch.<br>
<b>Tageswerte:</b> „Energie heute“ muss um Mitternacht auf 0 zurückgehen (so wie bei PVOutput erwartet). Wer nur die Leistung hat, wählt beim Feld <i>aus der Leistung berechnen</i>.<br>
Jedes andere MQTT-Topic lässt sich weiter als <i>Eigenes MQTT-Topic</i> eintragen.</p>

<?php } elseif ($tab === 'test') { ?>
<h3>Test</h3>
<form method="post" action="<?= $basis ?>test" data-ajax="false">
<input type="hidden" name="token" value="<?= h($token) ?>">
<button type="submit" name="aktion" value="zugang" data-ajax="false">PVOutput-Zugang prüfen</button>
<button type="submit" name="aktion" value="werte" data-ajax="false">Aktuelle Werte</button>
<button type="submit" name="aktion" value="selbst" data-ajax="false">Selbstprüfung</button>
<button type="submit" name="aktion" value="neustart" data-ajax="false">Dienst neu starten</button>
</form>
<p class="hinweis">„Zugang prüfen“ liest nur den letzten Status von PVOutput und schreibt nichts.</p>
<?php
    $a = (string)($_POST['aktion'] ?? '');
    $zeile = function (string $name, bool $ok, string $text): void {
        echo '<tr><td>' . h($name) . '</td><td class="' . ($ok ? 'ok' : 'fehler') . '">' . ($ok ? 'OK' : 'Fehler') . '</td><td>' . h($text) . '</td></tr>';
    };
    if ($a === 'zugang') {
        if (!PvoConfig::zugangOk($pvo)) {
            echo '<p class="fehler">API-Key oder System-ID fehlt.</p>';
        } else {
            $r = (new PvoClient($pvo['apikey'], $pvo['systemid']))->check();
            echo '<p class="' . ($r['ok'] ? 'ok' : 'fehler') . '">' . h($r['ok'] ? 'Zugang in Ordnung. ' . $r['msg'] : $r['msg']) . '</p>';
        }
    } elseif ($a === 'werte') {
        if (!$laeuft) {
            echo '<p class="fehler">Der Dienst läuft nicht, es gibt keine aktuellen Werte.</p>';
        } else {
            echo '<table><tr><th>Feld</th><th>Topic / Betriebsart</th><th>Rohwert</th><th>Alter</th><th>Wird gesendet</th></tr>';
            foreach ($felder as $k => $f) {
                if (empty($f['aktiv'])) {
                    continue;
                }
                $src = ($f['modus'] ?? '') === 'integral' ? 'berechnet aus der Leistung' : $f['topic'];
                echo '<tr><td>' . h($k . ' ' . $f['name']) . '</td><td>' . h($src) . '</td><td>' . ($f['roh'] !== null ? h($f['roh']) : '-') . '</td><td>'
                    . ($f['alter'] !== null ? h($f['alter']) . ' s' : '-') . '</td><td>' . ($f['wert'] !== null ? h(round((float)$f['wert'], 2)) . ' ' . h($f['einheit']) : '<span class="fehler">nein (kein frischer Wert)</span>') . '</td></tr>';
            }
            echo '</table>';
        }
    } elseif ($a === 'selbst') {
        echo '<table><tr><th>Prüfung</th><th>Ergebnis</th><th>Details</th></tr>';
        $zeile('Zugangsdaten', PvoConfig::zugangOk($pvo), PvoConfig::zugangOk($pvo) ? 'API-Key und System-ID eingetragen' : 'API-Key oder System-ID fehlt');
        $zeile('Upload', $pvo['aktiv'], $pvo['aktiv'] ? 'eingeschaltet, alle ' . $pvo['intervall'] . ' Minuten' : 'ausgeschaltet (Einstellungen: „Werte an pvoutput.org senden“)');
        $zeile('Messwerte', count(PvoConfig::topics($pvo)) > 0, count(PvoConfig::topics($pvo)) . ' Topics konfiguriert');
        if (PvoConfig::zugangOk($pvo)) {
            $r = (new PvoClient($pvo['apikey'], $pvo['systemid']))->check();
            $zeile('PVOutput', $r['ok'], $r['msg']);
        }
        $own = PvoConfig::ownBroker($pvo);
        $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1], 'brokeruser' => $own[2], 'brokerpass' => $own[3]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
        $mq = new MqttClient((string)($d['brokerhost'] ?? 'localhost'), (int)($d['brokerport'] ?? 1883), (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? ''), 'pvoutput_test_' . $paths->folder, 'pvoutput/status/test');
        $okm = $mq->connect();
        $zeile('Broker', $okm, $okm ? 'Verbindung möglich' : $mq->lastError());
        $mq->close();
        $gw = GatewayInfo::subscription('pvoutput/#', GatewayInfo::general());
        $zeile('Gateway-Abo', $gw['state'] === 'ok', $gw['text']);
        $sock = @stream_socket_client('udp://127.0.0.1:' . $udp, $en, $es, 2);
        if ($sock) {
            $sent = @fwrite($sock, 'publish ' . PvoConfig::LOXONE_PRAEFIX . 'test 1');
            fclose($sock);
            $zeile('Befehlsbrücke für Werte aus Loxone (UDP ' . $udp . ')', $sent > 0, 'Testnachricht gesendet (UDP liefert keine Antwort)');
        } else {
            $zeile('Befehlsbrücke für Werte aus Loxone (UDP ' . $udp . ')', false, 'Socket nicht möglich: ' . $es);
        }
        $zeile('Dienst', $laeuft, $laeuft ? 'läuft, letzte Statusmeldung vor ' . $alter . ' s' : 'keine aktuelle Statusmeldung');
        if ($laeuft) {
            $aktiv = array_filter($felder, fn($f) => !empty($f['aktiv']));
            $frisch = array_filter($aktiv, fn($f) => $f['wert'] !== null);
            $fehlt = array_keys(array_diff_key($aktiv, $frisch));
            $zeile('Frische Werte', count($aktiv) > 0 && count($frisch) === count($aktiv),
                count($frisch) . ' von ' . count($aktiv) . ' Feldern haben einen aktuellen Wert' . ($fehlt ? ' (es fehlt: ' . implode(', ', $fehlt) . ')' : ''));
        }
        echo '</table>';
    }
} elseif ($tab === 'logs') {
    echo '<h3>Logdateien</h3>';
    $dateien = glob(LBPLOGDIR . '/*.log') ?: [];
    if (!$dateien) {
        echo '<p>Noch keine Logdatei vorhanden.</p>';
    }
    foreach ($dateien as $f) {
        $size = (int)filesize($f);
        $text = (string)@file_get_contents($f, false, null, max(0, $size - 20000));   // nur das Ende lesen
        $zeilen = array_slice(explode("\n", $text), -200);
        echo '<h4>' . h(basename($f)) . ' (letzte Zeilen)</h4><pre>' . h(implode("\n", $zeilen)) . '</pre>';
    }
}
?>
</div>
<?php LBWeb::lbfooter(); ?>
