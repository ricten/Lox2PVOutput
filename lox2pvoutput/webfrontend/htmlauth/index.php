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

// 4. Parameter true: kein jQuery Mobile, Oberfläche im LoxBerry Design System (lb-*-Klassen)
LBWeb::lbheader('Lox2PVOutput ' . AppVersion::CURRENT, '', '', true);
?>
<style>
 /* nur noch Feinheiten; Aussehen, Farben und Dunkelmodus kommen vom LoxBerry Design System */
 .pvo .ok { color:var(--lb-success,#2a8a2a); font-weight:bold; } .pvo .fehler { color:var(--lb-danger,#c0392b); font-weight:bold; }
 .pvo .hinweis { color:var(--lb-text-muted,#666); font-size:90%; }
 .pvo pre { background:var(--lb-gray-50,#f4f4f4); color:var(--lb-text,#222); border:1px solid var(--lb-border-color,#ccc); border-radius:var(--lb-radius,4px); padding:8px; overflow:auto; max-height:420px; }
 .pvo td, .pvo th { vertical-align:top; }
</style>
<div class="lb-content pvo">
<?php foreach ($meldungen as $m) { echo '<p class="' . (str_starts_with($m, 'FEHLER') ? 'fehler' : 'ok') . '">' . h($m) . '</p>'; } ?>

<?php if ($tab === 'einstellungen') { ?>
<form method="post" action="<?= $basis ?>einstellungen">
<input type="hidden" name="token" value="<?= h($token) ?>">

<h3>PVOutput</h3>
<div class="lb-form-row">
    <label class="lb-form-label">Senden</label>
    <div class="lb-form-field">
        <label class="lb-toggle"><input type="checkbox" name="aktiv" value="1" <?= $pvo['aktiv'] ? 'checked' : '' ?>><span class="lb-toggle-slider"></span></label>
        Werte an pvoutput.org senden
    </div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="apikey">API-Key</label>
    <div class="lb-form-field"><input class="lb-input" type="password" name="apikey" id="apikey" value="" autocomplete="new-password" placeholder="<?= $pvo['apikey'] !== '' ? '(gesetzt)' : '' ?>"></div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="systemid">System-ID</label>
    <div class="lb-form-field"><input class="lb-input" type="text" name="systemid" id="systemid" value="<?= h($pvo['systemid']) ?>" inputmode="numeric"></div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="intervall">Sendeintervall</label>
    <div class="lb-form-field"><select class="lb-select" name="intervall" id="intervall">
<?php foreach (PvoConfig::INTERVALLE as $iv) { echo '<option value="' . $iv . '"' . ($pvo['intervall'] === $iv ? ' selected' : '') . '>alle ' . $iv . ' Minuten</option>'; } ?>
    </select></div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="max_alter">Werte gültig für (Sekunden)</label>
    <div class="lb-form-field"><input class="lb-input" type="number" name="max_alter" id="max_alter" min="60" max="86400" value="<?= (int)$pvo['max_alter'] ?>"></div>
</div>
<div class="lb-callout"><p class="hinweis">API-Key und System-ID stehen bei pvoutput.org unter <i>Settings → Registered Systems</i> bzw. <i>API Settings</i>.
Das Intervall muss zum <i>Status Interval</i> der PVOutput-Systemeinstellungen passen. Kostenlose Konten dürfen höchstens 60 Anfragen pro Stunde senden.
Ein leeres API-Key-Feld behält den gespeicherten Key. „Werte gültig für“: ein Wert, der älter ist, wird nicht mehr gesendet (und zählt bei der Energieberechnung nicht mit).</p></div>

<h3>Quellen</h3>
<?php
// Auswahllisten aus den Topics, die der Dienst von Fronius2Lox gesehen hat (fronius/<Name>/<Art>/<Nr>/...); ohne Funde ein Eingabefeld
$namen = array_keys((array)($entdeckt['namen'] ?? []));
sort($namen, SORT_STRING);
$aktName = (string)$pvo['quellen']['fronius_name'];
$namenListe = array_values(array_unique(array_merge($namen, [$aktName])));
if ($namen) { ?>
<div class="lb-form-row">
    <label class="lb-form-label" for="q_name">Fronius2Lox: Name des Wechselrichters</label>
    <div class="lb-form-field"><select class="lb-select" name="quellen[fronius_name]" id="q_name">
<?php foreach ($namenListe as $n) { echo '<option value="' . h($n) . '"' . ($n === $aktName ? ' selected' : '') . '>' . h($n) . (in_array($n, $namen, true) ? '' : ' (nicht erkannt)') . '</option>'; } ?>
    </select></div>
    <div class="lb-form-help hinweis">Auswahl aus den erkannten Topics <code>fronius/&lt;Name&gt;/…</code></div>
</div>
<?php } else { ?>
<div class="lb-form-row">
    <label class="lb-form-label" for="q_name">Fronius2Lox: Name des Wechselrichters</label>
    <div class="lb-form-field"><input class="lb-input" type="text" name="quellen[fronius_name]" id="q_name" value="<?= h($aktName) ?>"></div>
    <div class="lb-form-help hinweis">Topic <code>fronius/&lt;Name&gt;/…</code> (Standard wr1); noch keine Topics von Fronius2Lox gesehen, sonst gäbe es hier eine Auswahl</div>
</div>
<?php }
foreach (PvoConfig::NUMMERN as $typ => [$bez, $std]) {
    $gefunden = array_keys((array)($entdeckt['geraete'][$aktName][$typ] ?? []));
    sort($gefunden, SORT_NUMERIC);
    $hand = (string)$pvo['quellen']['fronius_' . $typ];
    $verw = (string)$pvo['quellen']['nr'][$typ]; ?>
<div class="lb-form-row">
    <label class="lb-form-label" for="q_<?= h($typ) ?>">Fronius2Lox: Nummer <?= h($bez) ?></label>
<?php if ($gefunden) { ?>
    <div class="lb-form-field"><select class="lb-select" name="quellen[fronius_<?= h($typ) ?>]" id="q_<?= h($typ) ?>">
<option value=""<?= $hand === '' ? ' selected' : '' ?>>automatisch (aktuell <?= h($verw) ?>)</option>
<?php foreach (array_values(array_unique(array_merge($gefunden, $hand !== '' ? [$hand] : []))) as $n) {
    echo '<option value="' . h($n) . '"' . ($hand === (string)$n ? ' selected' : '') . '>' . h($n) . (in_array((string)$n, array_map('strval', $gefunden), true) ? '' : ' (nicht erkannt)') . '</option>';
} ?>
    </select></div>
    <div class="lb-form-help hinweis">erkannt in <code>fronius/<?= h($aktName) ?>/<?= h($typ) ?>/&lt;Nr&gt;/…</code>; verwendet: <b><?= h($verw) ?></b></div>
<?php } else { ?>
    <div class="lb-form-field"><input class="lb-input" type="text" name="quellen[fronius_<?= h($typ) ?>]" id="q_<?= h($typ) ?>" value="<?= h($hand) ?>" placeholder="automatisch"></div>
    <div class="lb-form-help hinweis">leer = automatisch; noch nichts erkannt in <code>fronius/<?= h($aktName) ?>/<?= h($typ) ?>/&lt;Nr&gt;/…</code>, verwendet: <b><?= h($verw) ?></b> (Standard <?= h($std) ?>)</div>
<?php } ?>
</div>
<?php } ?>
<div class="lb-form-row">
    <label class="lb-form-label" for="q_senec">Senec2Lox: Topic-Präfix</label>
    <div class="lb-form-field"><input class="lb-input" type="text" name="quellen[senec_praefix]" id="q_senec" value="<?= h($pvo['quellen']['senec_praefix']) ?>"></div>
    <div class="lb-form-help hinweis">Topic <code>&lt;Präfix&gt;/live/…</code> (Standard senec)</div>
</div>

<h3>Woher kommen die Werte?</h3>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Feld</th><th>Quelle und Wert</th><th>Eigenes Topic</th><th>Faktor</th><th>Verwendetes Topic</th><th>Aktuell</th></tr>
<?php foreach (PvoConfig::FELDER as $k => [$name, $einheit, $art]) {
    $f = $pvo['felder'][$k];
    $live = $felder[$k] ?? null;
    $sel = PvoConfig::auswahlWert($f);
    ?>
<tr>
 <td><b><?= h($k) ?></b> <?= h($name) ?><?= $einheit !== '' ? ' <span class="hinweis">(' . h($einheit) . ')</span>' : '' ?></td>
 <td><select class="lb-select" name="felder[<?= $k ?>][auswahl]">
<?php foreach (PvoConfig::auswahl($k) as $gruppe => $eintraege) {
    echo '<optgroup label="' . h($gruppe) . '">';
    foreach ($eintraege as $key => $label) {
        echo '<option value="' . h($key) . '"' . ($sel === $key ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    echo '</optgroup>';
} ?>
 </select></td>
 <td><input class="lb-input" type="text" name="felder[<?= $k ?>][eigenes]" value="<?= h($f['eigenes']) ?>" placeholder="nur bei „Eigenes Topic“"></td>
 <td><input class="lb-input" type="text" name="felder[<?= $k ?>][faktor]" value="<?= h(rtrim(rtrim(number_format((float)$f['faktor'], 6, '.', ''), '0'), '.')) ?>" style="min-width:70px"></td>
 <td><?php
    if (($f['modus'] ?? '') === 'integral') {
        echo '<span class="hinweis">berechnet aus ' . h($pvo['felder'][$k === 'v1' ? 'v2' : 'v4']['topic'] ?: 'der Leistung (kein Topic gewählt)') . '</span>';
    } else {
        echo $f['topic'] !== '' ? '<code>' . h($f['topic']) . '</code>' : '<span class="hinweis">nicht gesendet</span>';
    } ?></td>
 <td><?php if ($live && $live['wert'] !== null) { echo h(round((float)$live['wert'], 2)) . ' ' . h($einheit); } elseif ($live && !empty($live['aktiv'])) { echo '<span class="fehler">kein Wert</span>'; } else { echo '-'; } ?></td>
</tr>
<?php } ?>
</table></div>
<div class="lb-callout"><p class="hinweis">
<b>Quelle und Wert</b>: Aus der Liste wählen, welches Plugin den Wert liefert (z. B. <i>Fronius2Lox → PV-Leistung</i>). Das Plugin setzt das Topic selbst zusammen und zeigt es in der Spalte „Verwendetes Topic“.
Mit <i>Eigenes MQTT-Topic</i> lässt sich jedes andere Topic eintragen (genau ein Topic ohne Platzhalter, nur reine Zahlen), z. B. <code>loxone/aussentemperatur</code>.
Bei einer Quelle gelten die Nummern des ersten Geräts (<code>wr/1</code>, <code>zaehler/0</code>); für weitere Geräte ein eigenes Topic verwenden.<br>
<b>Faktor</b>: Wert × Faktor ergibt die PVOutput-Einheit (z. B. 1000 bei kW → W, 1000 bei kWh → Wh). Leistung (W) und Energie (Wh) werden nie negativ gesendet.<br>
<b>Erzeugung/Verbrauch Energie (v1/v3)</b>: „aus der Leistung berechnen“ summiert v2 bzw. v4 seit Mitternacht (Europe/Berlin) und braucht kein eigenes Topic.
Ein <i>Tageswert</i> (Wh seit Mitternacht) wird direkt gesendet; ein <i>Gesamtzähler</i> (Wh, ewig steigend) ebenfalls, PVOutput bildet dann die Tagessumme selbst. Die Betriebsart ergibt sich aus der Auswahl.
Ein GEN24 liefert keinen Tageswert; ein Symo (Non-Hybrid) liefert <i>Erzeugung heute</i>, das ist meist die genaueste Wahl.<br>
<b>v7–v12</b> (erweiterte Werte) nimmt PVOutput nur mit Spenden-Konto an; hier stehen alle Werte der Quellen zur Auswahl.</p></div>
<p><button type="submit" class="lb-btn lb-btn-primary" name="aktion" value="speichern">Speichern</button></p>
</form>

<?php } elseif ($tab === 'mqtt') {
    $own = PvoConfig::ownBroker($pvo);
    $d = $own !== null ? ['brokerhost' => $own[0], 'brokerport' => $own[1]] : (function_exists('mqtt_connectiondetails') ? mqtt_connectiondetails() : []);
    $gw = GatewayInfo::subscription('pvoutput/#', GatewayInfo::general());
    $up = (array)($status['upload'] ?? []);
    ?>
<h3>MQTT</h3>
<div class="lb-table-scroll"><table class="lb-table">
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
</table></div>
<form method="post" action="<?= $basis ?>mqtt">
<input type="hidden" name="token" value="<?= h($token) ?>">
<h3>MQTT-Broker</h3>
<?php $mq = $pvo['mqtt']; ?>
<div class="lb-form-row">
    <label class="lb-form-label">Eigener Broker</label>
    <div class="lb-form-field">
        <label class="lb-toggle"><input type="checkbox" name="mqtt[eigener_broker]" value="1" <?= !empty($mq['eigener_broker']) ? 'checked' : '' ?>><span class="lb-toggle-slider"></span></label>
        Eigenen MQTT-Broker verwenden (statt dem Broker des LoxBerry)
    </div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="m_host">Host/IP</label>
    <div class="lb-form-field"><input class="lb-input" type="text" name="mqtt[host]" id="m_host" value="<?= h($mq['host']) ?>"></div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="m_port">Port</label>
    <div class="lb-form-field"><input class="lb-input" type="number" name="mqtt[port]" id="m_port" value="<?= (int)$mq['port'] ?>"></div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="m_user">Benutzer</label>
    <div class="lb-form-field"><input class="lb-input" type="text" name="mqtt[benutzer]" id="m_user" value="<?= h($mq['benutzer']) ?>" autocomplete="off"></div>
</div>
<div class="lb-form-row">
    <label class="lb-form-label" for="m_pass">Passwort</label>
    <div class="lb-form-field"><input class="lb-input" type="password" name="mqtt[passwort]" id="m_pass" value="" autocomplete="new-password" placeholder="<?= $mq['passwort'] !== '' ? '(gesetzt)' : '' ?>"></div>
</div>
<div class="lb-callout"><p class="hinweis">Von diesem Broker liest das Plugin die Messwerte und veröffentlicht darüber seine Status-Topics. Ohne Haken gilt der Broker aus der LoxBerry-Konfiguration (empfohlen, Zugangsdaten verwaltet LoxBerry).
Mit eigenem Broker erreichen die Status-Topics den Miniserver nur, wenn das MQTT-Gateway diesen Broker abonniert. Ein leeres Passwortfeld behält das gespeicherte Passwort.</p></div>
<p><button type="submit" class="lb-btn lb-btn-primary" name="aktion" value="speichern">Speichern</button></p>
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
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Topic</th><th>Eingangsname</th><th>Einheit</th><th>Bedeutung</th><th>retained</th></tr>
<?php foreach ($werte as $w) {
    echo '<tr><td>' . h($w[0]) . '</td><td>' . h(str_replace('/', '_', $w[0])) . '</td><td>' . h($w[1]) . '</td><td>' . h($w[2]) . '</td><td>nein</td></tr>';
} ?>
</table></div>
<p class="hinweis">Beim Absturz des Dienstes setzt der Broker pvoutput/status/ok per Letzter-Wille-Nachricht auf 0 (retained).</p>

<h3>Werte aus Loxone an PVOutput senden</h3>
<?php $lbip = (string)($_SERVER['SERVER_ADDR'] ?? ''); $lbip = ($lbip === '' || $lbip === '::1') ? '<LoxBerry-IP>' : $lbip; ?>
<p>Werte, die nur im Miniserver stehen (z. B. Außentemperatur, Zählerstand, Netzspannung), schickt ein <b>virtueller Ausgang</b> an das MQTT-Gateway. Jedes PVOutput-Feld hat dafür einen <b>festen Eingang</b>.
Im Reiter <i>Einstellungen</i> wählt man beim Feld dann in der Liste <i>Loxone (virtueller Ausgang)</i>.</p>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>Eintrag in Loxone Config</th><th>Wert</th></tr>
<tr><td>Virtueller Ausgang, Adresse</td><td><code>/dev/udp/<?= h($lbip) ?>/<?= (int)$udp ?></code></td></tr>
<tr><td>Befehl bei EIN</td><td><code>publish &lt;Topic aus der Tabelle&gt; &lt;v&gt;</code></td></tr>
<tr><td>Trennzeichen / HTTP</td><td>leer, <b>ohne</b> HTTP-Header/Body</td></tr>
</table></div>
<div class="lb-table-scroll"><table class="lb-table">
<tr><th>PVOutput-Feld</th><th>Wert</th><th>Befehl bei EIN (Loxone)</th></tr>
<?php foreach (PvoConfig::loxoneEingaenge() as $e) {
    $f = PvoConfig::FELDER[$e['feld']];
    echo '<tr><td><b>' . h($e['feld']) . '</b> ' . h($f[0]) . '</td><td>' . h($e['bezeichnung']) . ($e['art'] === 'zaehler' ? ' – Zählerstand, steigt ständig' : '') . '</td><td><code>publish ' . h($e['topic']) . ' &lt;v&gt;</code></td></tr>';
} ?>
</table></div>
<p class="hinweis">
<b>Format:</b> eine reine Zahl, mit Punkt oder Komma (<code>21.5</code> oder <code>21,5</code>). Eine angehängte Einheit wie <code>21,5 °C</code> wird ignoriert, aber nicht umgerechnet; liefert Loxone kW statt W, trägt man den Faktor 1000 ein. <code>&lt;v&gt;</code> ersetzt Loxone durch den Wert des angeschlossenen Ausgangs.<br>
<b>Pro Wert ein Befehl:</b> An einen virtuellen Ausgang lassen sich mehrere Befehle hängen, jeder an seinem Analogwert. Wer mehrere Felder schickt, legt mehrere Befehle an.<br>
<b>Regelmäßig senden:</b> Der Wert gilt nur „Werte gültig für“ Sekunden (Einstellungen, Standard 15 Minuten). Den Ausgang deshalb zyklisch senden lassen (z. B. jede Minute) oder bei jeder Änderung <i>und</i> zyklisch.<br>
<b>Tageswerte:</b> „Energie heute“ muss um Mitternacht auf 0 zurückgehen (so wie bei PVOutput erwartet). Wer nur die Leistung hat, wählt beim Feld <i>aus der Leistung berechnen</i>.<br>
Jedes andere MQTT-Topic lässt sich weiter als <i>Eigenes MQTT-Topic</i> eintragen.</p>

<?php } elseif ($tab === 'test') { ?>
<h3>Test</h3>
<form method="post" action="<?= $basis ?>test">
<input type="hidden" name="token" value="<?= h($token) ?>">
<button type="submit" class="lb-btn lb-btn-primary" name="aktion" value="zugang">PVOutput-Zugang prüfen</button>
<button type="submit" class="lb-btn" name="aktion" value="werte">Aktuelle Werte</button>
<button type="submit" class="lb-btn" name="aktion" value="selbst">Selbstprüfung</button>
<button type="submit" class="lb-btn" name="aktion" value="neustart">Dienst neu starten</button>
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
            echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Feld</th><th>Topic / Betriebsart</th><th>Rohwert</th><th>Alter</th><th>Wird gesendet</th></tr>';
            foreach ($felder as $k => $f) {
                if (empty($f['aktiv'])) {
                    continue;
                }
                $src = ($f['modus'] ?? '') === 'integral' ? 'berechnet aus der Leistung' : $f['topic'];
                echo '<tr><td>' . h($k . ' ' . $f['name']) . '</td><td>' . h($src) . '</td><td>' . ($f['roh'] !== null ? h($f['roh']) : '-') . '</td><td>'
                    . ($f['alter'] !== null ? h($f['alter']) . ' s' : '-') . '</td><td>' . ($f['wert'] !== null ? h(round((float)$f['wert'], 2)) . ' ' . h($f['einheit']) : '<span class="fehler">nein (kein frischer Wert)</span>') . '</td></tr>';
            }
            echo '</table></div>';
        }
    } elseif ($a === 'selbst') {
        echo '<div class="lb-table-scroll"><table class="lb-table"><tr><th>Prüfung</th><th>Ergebnis</th><th>Details</th></tr>';
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
        echo '</table></div>';
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
