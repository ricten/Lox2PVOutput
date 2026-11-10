<?php
/**
 * Test-Runner ohne Abhaengigkeiten: php tests/run.php
 * Teil 1: Unit-Tests (Konfiguration, Sammler/Tagesenergie, PVOutput-Client gegen Fake-Server)
 * Teil 2: Ende-zu-Ende: der Dienst (pvoutputd.php im Entwicklungsmodus) gegen Fake-Broker und Fake-PVOutput.
 * Nicht getestet werden koennen hier: LoxBerry-Bibliotheken, Weboberflaeche, echte PVOutput-Antworten.
 */
$root = dirname(__DIR__);
date_default_timezone_set('Europe/Berlin');
// Gemeinsamer Code direkt aus ../common (im ZIP liegt er in bin/lib)
foreach (['Util', 'Logger', 'MqttClient'] as $c) {
    require_once $root . '/../common/php/' . $c . '.php';
}
foreach (['PvoConfig', 'PvoClient', 'Collector', 'AppVersion'] as $c) {
    require_once $root . '/lox2pvoutput/bin/lib/' . $c . '.php';
}

$fails = 0;
$count = 0;
function check(string $name, $actual, $expected): void
{
    global $fails, $count;
    $count++;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $name: erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    }
}
function waitFor(callable $f, float $timeout = 10.0): bool
{
    $end = microtime(true) + $timeout;
    while (microtime(true) < $end) {
        if ($f()) {
            return true;
        }
        usleep(100000);
    }
    return false;
}
function startProc(array $cmd, array $env = []): array
{
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/pvo_test_out.txt', 'a'], 2 => ['file', sys_get_temp_dir() . '/pvo_test_err.txt', 'a']], $pipes, null, $env ? array_merge(getenv(), $env) : null);
    return [$p, $pipes];
}
function stopProc(array $pp): void
{
    @proc_terminate($pp[0]);
    foreach ($pp[1] as $h) {
        @fclose($h);
    }
    @proc_close($pp[0]);
}

$tmp = __DIR__ . '/tmp';
@mkdir($tmp, 0777, true);
$dir = $tmp . '/t' . getmypid();
@mkdir($dir, 0777, true);

// ---------------------------------------------------------------- Unit: Konfiguration
check('cleanTopic normal', PvoConfig::cleanTopic(' senec/live/pv_w '), 'senec/live/pv_w');
check('cleanTopic Platzhalter', PvoConfig::cleanTopic('senec/#'), '');
check('cleanTopic Plus', PvoConfig::cleanTopic('a/+/b'), '');
check('cleanTopic Steuerzeichen', PvoConfig::cleanTopic("a\nb"), '');
check('cleanTopic Schraegstriche', PvoConfig::cleanTopic('/a/b/'), 'a/b');

$d = PvoConfig::defaults();
check('Vorgabe aus', $d['aktiv'], false);
check('Vorgabe v2-Topic', $d['felder']['v2']['topic'], 'senec/live/pv_w');
check('Vorgabe v1 integral', $d['felder']['v1']['modus'], 'integral');
check('Vorgabe: Zugang fehlt', PvoConfig::zugangOk($d), false);
check('Vorgabe: Topics', PvoConfig::topics($d), ['v2' => 'senec/live/pv_w', 'v4' => 'senec/live/haus_w']);

$cfgFile = $dir . '/pvoutput.json';
$p = PvoConfig::fromPost($d, [
    'aktiv' => '1', 'apikey' => ' ab12CD34 ef!', 'systemid' => 'x12345y', 'intervall' => '10', 'max_alter' => '5',
    'felder' => [
        'v1' => ['modus' => 'zaehler', 'topic' => 'zaehler/erzeugt_wh', 'faktor' => '1'],
        'v2' => ['topic' => 'senec/live/pv_w', 'faktor' => '0,001'],
        'v4' => ['topic' => 'senec/live/haus_w', 'faktor' => '1'],
        'v3' => ['modus' => 'unsinn', 'topic' => '', 'faktor' => 'abc'],
        'v5' => ['topic' => 'temp/aussen', 'faktor' => '1'],
        'v6' => ['topic' => 'bad/#', 'faktor' => '0'],
    ],
]);
check('post aktiv', $p['aktiv'], true);
check('post apikey bereinigt', $p['apikey'], 'ab12CD34ef');
check('post systemid nur Ziffern', $p['systemid'], '12345');
check('post intervall', $p['intervall'], 10);
check('post max_alter min', $p['max_alter'], 60);
check('post faktor Komma', $p['felder']['v2']['faktor'], 0.001);
check('post ungueltiger Modus -> Standard', $p['felder']['v3']['modus'], 'integral');
check('post ungueltiger Faktor -> 1', $p['felder']['v3']['faktor'], 1.0);
check('post Faktor 0 -> 1', $p['felder']['v6']['faktor'], 1.0);
check('post Platzhalter-Topic entfernt', $p['felder']['v6']['topic'], '');
check('post unbekanntes Feld bleibt leer', $p['felder']['v8']['topic'], '');
check('Zugang ok', PvoConfig::zugangOk($p), true);
check('v1 Zaehler: Topic wird abonniert', PvoConfig::topics($p)['v1'] ?? null, 'zaehler/erzeugt_wh');
check('v3 integral: braucht v4-Topic (vorhanden)', PvoConfig::feldAktiv($p, 'v3'), true);
$q = PvoConfig::fromPost($p, ['apikey' => '', 'systemid' => '999', 'felder' => []]);
check('leerer API-Key behaelt den alten', $q['apikey'], 'ab12CD34ef');
$noP = $p;
$noP['felder']['v4']['topic'] = '';
check('v3 integral ohne v4-Topic inaktiv', PvoConfig::feldAktiv($noP, 'v3'), false);
$m = PvoConfig::fromPost($p, ['mqtt' => ['eigener_broker' => '1', 'host' => ' b.lan ', 'port' => '8883', 'benutzer' => 'u', 'passwort' => 'pw']]);
check('nur MQTT gepostet: Felder bleiben', $m['felder']['v2']['topic'], 'senec/live/pv_w');
check('nur MQTT gepostet: aktiv bleibt', $m['aktiv'], true);
check('ownBroker', PvoConfig::ownBroker($m), ['b.lan', 8883, 'u', 'pw']);
check('ownBroker aus', PvoConfig::ownBroker($d), null);
check('speichern', Util::jsonSave($cfgFile, $p), true);
if (DIRECTORY_SEPARATOR === '/') {
    check('Rechte 0600', substr(sprintf('%o', fileperms($cfgFile)), -4), '0600');
}
check('laden', PvoConfig::load($cfgFile)['systemid'], '12345');
check('laden: Faktor', PvoConfig::load($cfgFile)['felder']['v2']['faktor'], 0.001);

// ---------------------------------------------------------------- Unit: Auswahl der Quelle
$d0 = PvoConfig::defaults();
check('Vorgabe v2: Quelle Senec2Lox', [$d0['felder']['v2']['quelle'], $d0['felder']['v2']['wert']], ['senec2lox', 'senec2lox:pv_w']);
check('Vorgabe v1: aus der Leistung berechnen', [$d0['felder']['v1']['quelle'], $d0['felder']['v1']['modus']], ['aus', 'integral']);
check('Vorgabe Quellen', $d0['quellen'], ['fronius_name' => 'wr1', 'senec_praefix' => 'senec', 'fronius_wr' => '', 'fronius_zaehler' => '', 'fronius_ohmpilot' => '', 'fronius_sensor' => '']);
$sel = fn(array $post, ?array $base = null) => PvoConfig::fromPost($base ?? $d0, ['systemid' => '1', 'felder' => $post]);
$fr = $sel(['v2' => ['auswahl' => 'fronius:pv_w'], 'v4' => ['auswahl' => 'fronius:haus_w'], 'v1' => ['auswahl' => 'fronius:energie_tag_wh'],
    'v5' => ['auswahl' => 'fronius:sensor_temp1'], 'v6' => ['auswahl' => 'fronius:wr_spannung_ac']]);
check('Fronius: v2 Topic', $fr['felder']['v2']['topic'], 'fronius/wr1/pv_w');
check('Fronius: v4 Topic', $fr['felder']['v4']['topic'], 'fronius/wr1/haus_w');
check('Fronius: v1 Tageswert -> Betriebsart topic', [$fr['felder']['v1']['modus'], $fr['felder']['v1']['topic']], ['topic', 'fronius/wr1/energie_tag_wh']);
check('Fronius: v5 Sensorkarte', $fr['felder']['v5']['topic'], 'fronius/wr1/sensor/1/kanal_0');
check('Fronius: v6 Spannung mit Pfad', $fr['felder']['v6']['topic'], 'fronius/wr1/wr/1/spannung_ac_v');
check('Fronius: Topics werden abonniert', array_values(PvoConfig::topics($fr)), ['fronius/wr1/energie_tag_wh', 'fronius/wr1/pv_w', 'fronius/wr1/haus_w', 'fronius/wr1/sensor/1/kanal_0', 'fronius/wr1/wr/1/spannung_ac_v']);
$zz = $sel(['v1' => ['auswahl' => 'fronius:energie_gesamt_wh']]);
check('Gesamtzaehler -> Betriebsart zaehler', $zz['felder']['v1']['modus'], 'zaehler');
$qn = PvoConfig::fromPost($fr, ['systemid' => '1', 'felder' => ['v2' => ['auswahl' => 'fronius:pv_w']], 'quellen' => ['fronius_name' => 'Symo Haus', 'senec_praefix' => '/meinsenec/']]);
check('Quellen: Geraetename bereinigt', $qn['quellen']['fronius_name'], 'symo_haus');
check('Quellen: Praefix bereinigt', $qn['quellen']['senec_praefix'], 'meinsenec');
check('Quellen: Topic folgt dem Namen', $qn['felder']['v2']['topic'], 'fronius/symo_haus/pv_w');
$sn = $sel(['v2' => ['auswahl' => 'senec2lox:pv_w']], $qn);
check('Quellen: Senec-Praefix', $sn['felder']['v2']['topic'], 'meinsenec/live/pv_w');
$ql = PvoConfig::fromPost($sn, ['systemid' => '1', 'felder' => [], 'quellen' => ['fronius_name' => '', 'senec_praefix' => '']]);
check('Quellen: leer = Standard', $ql['quellen'], ['fronius_name' => 'wr1', 'senec_praefix' => 'senec', 'fronius_wr' => '', 'fronius_zaehler' => '', 'fronius_ohmpilot' => '', 'fronius_sensor' => '']);

// --- Geraetenummern (Wechselrichter 15 statt 1): von Hand oder aus den Topics erkannt
check('Nummern: Standard ohne Erkennung', PvoConfig::nummern($d0['quellen'], []), ['wr' => '1', 'zaehler' => '0', 'ohmpilot' => '0', 'sensor' => '1']);
check('Nummern: erkannt (Wechselrichter 15)', PvoConfig::nummern($d0['quellen'], ['geraete' => ['wr1' => ['wr' => ['15' => 1000]]]])['wr'], '15');
check('Nummern: von Hand geht vor', PvoConfig::nummern(['fronius_wr' => '3'] + $d0['quellen'], ['geraete' => ['wr1' => ['wr' => ['15' => 1000]]]])['wr'], '3');
check('Nummern: neuer verdraengt alten (alte Nummer 1 seit Tagen nicht gesehen)', PvoConfig::nummern($d0['quellen'], ['geraete' => ['wr1' => ['wr' => ['1' => 1000, '15' => 1000 + 86400]]]])['wr'], '15');
check('Nummern: zwei aktuelle -> die kleinste', PvoConfig::nummern($d0['quellen'], ['geraete' => ['wr1' => ['wr' => ['15' => 5000, '2' => 4500]]]])['wr'], '2');
check('Nummern: unbrauchbare Eintraege ignoriert', PvoConfig::nummern($d0['quellen'], ['geraete' => ['wr1' => ['wr' => ['x' => 'y']]]])['wr'], '1');
$pq = $d0;
$pq['felder']['v6'] = ['quelle' => 'fronius', 'wert' => 'fronius:wr_spannung_ac', 'eigenes' => '', 'faktor' => 1.0];
$pq['felder']['v5'] = ['quelle' => 'fronius', 'wert' => 'fronius:zaehler_leistung', 'eigenes' => '', 'faktor' => 1.0];
$pq = PvoConfig::finalize($pq);
check('Topic ohne Erkennung: Standardnummer', $pq['felder']['v6']['topic'], 'fronius/wr1/wr/1/spannung_ac_v');
$pe = PvoConfig::mitEntdeckten($pq, ['geraete' => ['wr1' => ['wr' => ['15' => 1000], 'zaehler' => ['0' => 1000]]]]);
check('Topic mit erkanntem Wechselrichter 15', $pe['felder']['v6']['topic'], 'fronius/wr1/wr/15/spannung_ac_v');
check('Topic Smart Meter mit erkannter Nummer', $pe['felder']['v5']['topic'], 'fronius/wr1/zaehler/0/leistung_w');
$ph = PvoConfig::fromPost($pq, ['felder' => [], 'quellen' => ['fronius_name' => 'wr1', 'senec_praefix' => 'senec', 'fronius_wr' => '7']]);
check('Nummer von Hand aus dem Formular (7)', $ph['quellen']['fronius_wr'], '7');
$pq7 = $pq;
$pq7['quellen']['fronius_wr'] = '7';
check('Topic mit Nummer von Hand (7) geht vor der erkannten 15', PvoConfig::mitEntdeckten($pq7, ['geraete' => ['wr1' => ['wr' => ['15' => 1000]]]])['felder']['v6']['topic'], 'fronius/wr1/wr/7/spannung_ac_v');
check('Nummer von Hand: ungueltig = automatisch, Nullen weg', PvoConfig::cleanQuellen(['fronius_wr' => 'abc'])['fronius_wr'] . '|' . PvoConfig::cleanQuellen(['fronius_zaehler' => ' 004 '])['fronius_zaehler'], '|4');
check('Geraet aus Topic: Wechselrichter 15', PvoConfig::geraetAusTopic('fronius/symo/wr/15/leistung_ac_w'), ['symo', 'wr', '15']);
check('Geraet aus Topic: Smart Meter 0', PvoConfig::geraetAusTopic('fronius/wr1/zaehler/0/leistung_w'), ['wr1', 'zaehler', '0']);
check('Geraet aus Topic: anderer Name wird mit erkannt', PvoConfig::geraetAusTopic('fronius/garage/wr/15/leistung_ac_w')[0], 'garage');
check('Geraet aus Topic: kein Geraet = nichts', PvoConfig::geraetAusTopic('fronius/wr1/pv_w'), null);
check('Geraet aus Topic: keine Ziffer = nichts', PvoConfig::geraetAusTopic('fronius/wr1/wr/abc/leistung_ac_w'), null);
check('Geraet aus Topic: fremdes Topic = nichts', PvoConfig::geraetAusTopic('senec/wr/15/leistung_ac_w'), null);
check('Nummern gelten je Name: anderer Name sieht die 15 nicht', PvoConfig::nummern(['fronius_name' => 'garage'] + $d0['quellen'], ['geraete' => ['wr1' => ['wr' => ['15' => 1000]]]])['wr'], '1');
check('Erkennung: Filter mit Wildcard fuer Name und Nummer (immer)', PvoConfig::entdeckungsFilter(), ['fronius/+/wr/+/leistung_ac_w', 'fronius/+/zaehler/+/leistung_w', 'fronius/+/ohmpilot/+/leistung_w', 'fronius/+/sensor/+/kanal_0']);

// Auswahl, die nicht zum Feld passt, wird abgelehnt
$bad = $sel(['v2' => ['auswahl' => 'fronius:energie_tag_wh'], 'v1' => ['auswahl' => 'fronius:pv_w'], 'v5' => ['auswahl' => 'senec2lox:pv_w'], 'v6' => ['auswahl' => 'unbekannt:x']]);
check('Unpassende Art v2 abgelehnt', $bad['felder']['v2']['quelle'], 'aus');
check('Unpassende Art v1 abgelehnt', [$bad['felder']['v1']['quelle'], $bad['felder']['v1']['modus']], ['aus', 'integral']);
check('Unpassende Art v5 abgelehnt', $bad['felder']['v5']['topic'], '');
check('Unbekannte Quelle abgelehnt', $bad['felder']['v6']['topic'], '');
$ext = $sel(['v7' => ['auswahl' => 'fronius:batterie_soc'], 'v8' => ['auswahl' => 'senec2lox:phase_l2'], 'v9' => ['auswahl' => 'fronius:pv_w']]);
check('Erweitertes Feld nimmt alles: SOC', $ext['felder']['v7']['topic'], 'fronius/wr1/batterie_soc');
check('Erweitertes Feld: Senec Phase', $ext['felder']['v8']['topic'], 'senec/live/phase_l2_w');
check('Erweitertes Feld: Leistung', $ext['felder']['v9']['topic'], 'fronius/wr1/pv_w');
// Betriebsart und eigenes Topic ueber die Auswahlliste
$eg = $sel(['v1' => ['auswahl' => 'eigen_zaehler', 'eigenes' => 'zaehler/erzeugt'], 'v3' => ['auswahl' => 'eigen_tag', 'eigenes' => 'zaehler/tag'],
    'v5' => ['auswahl' => 'eigen', 'eigenes' => 'loxone/aussen'], 'v2' => ['auswahl' => 'aus'], 'v4' => ['auswahl' => 'eigen', 'eigenes' => 'a/b#']]);
check('Eigenes Topic als Gesamtzaehler', [$eg['felder']['v1']['modus'], $eg['felder']['v1']['topic']], ['zaehler', 'zaehler/erzeugt']);
check('Eigenes Topic als Tageswert', [$eg['felder']['v3']['modus'], $eg['felder']['v3']['topic']], ['topic', 'zaehler/tag']);
check('Eigenes Topic v5', $eg['felder']['v5']['topic'], 'loxone/aussen');
check('Nicht senden', $eg['felder']['v2']['topic'], '');
check('Eigenes Topic mit Platzhalter wird leer', $eg['felder']['v4']['topic'], '');
$ig = $sel(['v1' => ['auswahl' => 'integral'], 'v3' => ['auswahl' => 'aus']], $eg);
check('Zurueck zu aus der Leistung berechnen', $ig['felder']['v1']['modus'], 'integral');
check('Energie nicht senden', PvoConfig::feldAktiv($ig, 'v3'), false);
check('auswahlWert: berechnen', PvoConfig::auswahlWert($ig['felder']['v1']), 'integral');
check('auswahlWert: aus', PvoConfig::auswahlWert($ig['felder']['v3']), 'aus');
check('auswahlWert: Katalog', PvoConfig::auswahlWert($fr['felder']['v2']), 'fronius:pv_w');
check('auswahlWert: eigenes Tageswert-Topic', PvoConfig::auswahlWert($eg['felder']['v3']), 'eigen_tag');
check('auswahlWert: eigenes Topic', PvoConfig::auswahlWert($eg['felder']['v5']), 'eigen');
check('auswahlWert: Gesamtzaehler', PvoConfig::auswahlWert($zz['felder']['v1']), 'fronius:energie_gesamt_wh');
$a1 = PvoConfig::auswahl('v1');
check('Liste v1: Betriebsarten', array_keys($a1['Betriebsart']), ['integral', 'aus', 'eigen_tag', 'eigen_zaehler']);
check('Liste v1: Fronius nur Tages- und Gesamtwerte', array_keys($a1['Fronius2Lox']), ['fronius:energie_tag_wh', 'fronius:wr_energie_tag_wh', 'fronius:energie_gesamt_wh', 'fronius:wr_energie_gesamt_wh']);
check('Liste v1: keine Senec-Eintraege (kein Tageswert)', isset($a1['Senec2Lox']), false);
check('Liste v2: Betriebsarten', array_keys(PvoConfig::auswahl('v2')['Betriebsart']), ['aus', 'eigen']);
check('Liste v2: Senec Leistungen enthalten PV', isset(PvoConfig::auswahl('v2')['Senec2Lox']['senec2lox:pv_w']), true);
check('Liste v2: keine Temperatur', isset(PvoConfig::auswahl('v2')['Fronius2Lox']['fronius:ohmpilot_temp']), false);
check('Liste v5: nur Temperaturen', array_keys(PvoConfig::auswahl('v5')['Fronius2Lox']), ['fronius:temp_umgebung', 'fronius:sensor_temp1', 'fronius:sensor_temp2', 'fronius:ohmpilot_temp']);
check('Liste v7: alles', count(PvoConfig::auswahl('v7')['Fronius2Lox']) > 20, true);
check('Katalog: alle Eintraege mit gueltiger Art', count(array_filter(PvoConfig::KATALOG, fn($e) => !in_array($e[2], ['leistung', 'tag', 'zaehler', 'temp', 'spannung', 'sonst'], true))), 0);
check('Katalog: Topics ohne Leerzeichen und Platzhalter', count(array_filter(PvoConfig::KATALOG, fn($e) => preg_match('/[\s+#]/', $e[0]))), 0);
// gespeicherte Konfiguration mit Quelle ueberlebt Speichern und Laden
$sf = $dir . '/quelle.json';
Util::jsonSave($sf, $fr);
$re = PvoConfig::load($sf);
check('Laden: Quelle bleibt', [$re['felder']['v2']['quelle'], $re['felder']['v2']['wert'], $re['felder']['v2']['topic']], ['fronius', 'fronius:pv_w', 'fronius/wr1/pv_w']);
check('Laden: Betriebsart bleibt', $re['felder']['v1']['modus'], 'topic');
// aeltere Konfiguration (nur "topic" je Feld) wird uebernommen
Util::jsonSave($sf, ['felder' => ['v2' => ['topic' => 'mein/pv', 'faktor' => 1], 'v5' => ['topic' => '']]]);
$lg = PvoConfig::load($sf);
check('Alte Konfiguration: Topic als eigenes Topic', [$lg['felder']['v2']['quelle'], $lg['felder']['v2']['topic']], ['topic', 'mein/pv']);
check('Alte Konfiguration: leeres Topic = nicht senden', $lg['felder']['v5']['quelle'], 'aus');
// ungueltige Eintraege in der Datei
Util::jsonSave($sf, ['felder' => ['v2' => ['quelle' => 'fronius', 'wert' => 'fronius:energie_tag_wh'], 'v4' => ['quelle' => 'fronius', 'wert' => 'senec2lox:haus_w']]]);
$bl = PvoConfig::load($sf);
check('Datei: unpassende Auswahl wird verworfen', [$bl['felder']['v2']['quelle'], $bl['felder']['v2']['topic']], ['aus', '']);
check('Datei: Quelle und Wert passen nicht zusammen', $bl['felder']['v4']['quelle'], 'aus');

// ---------------------------------------------------------------- Unit: Werte aus Loxone
foreach ([['21.5', 21.5], ['21,5', 21.5], ['21,5 °C', 21.5], ['3.2 kW', 3.2], ['-7', -7.0], ['1e3', 1000.0], ['  42  ', 42.0], ['5 W/m²', 5.0], ['4,5%', 4.5],
    ['230V', 230.0], ['0', 0.0], ['12:30', null], ['abc', null], ['', null], ['21.5.3', null], ['NaN', null], ['1.234,5', null], ['21,5 °C extra', null], ['--3', null]] as [$in, $exp]) {
    check("parse '$in'", Collector::parse($in), $exp);
}
$lx = fn(array $post, ?array $base = null) => PvoConfig::fromPost($base ?? PvoConfig::defaults(), ['systemid' => '1', 'felder' => $post]);
$lo = $lx(['v5' => ['auswahl' => 'loxone:v5'], 'v2' => ['auswahl' => 'loxone:v2'], 'v1' => ['auswahl' => 'loxone:v1_tag'], 'v3' => ['auswahl' => 'loxone:v3_gesamt'],
    'v7' => ['auswahl' => 'loxone:v7'], 'v12' => ['auswahl' => 'loxone:v12']]);
check('Loxone: v5 Topic', $lo['felder']['v5']['topic'], 'loxone/pvoutput/temperatur_c');
check('Loxone: v2 Topic', $lo['felder']['v2']['topic'], 'loxone/pvoutput/erzeugung_leistung_w');
check('Loxone: v1 Tageswert', [$lo['felder']['v1']['modus'], $lo['felder']['v1']['topic']], ['topic', 'loxone/pvoutput/erzeugung_energie_tag_wh']);
check('Loxone: v3 Gesamtzaehler', [$lo['felder']['v3']['modus'], $lo['felder']['v3']['topic']], ['zaehler', 'loxone/pvoutput/verbrauch_energie_gesamt_wh']);
check('Loxone: v7 erweitert 1', $lo['felder']['v7']['topic'], 'loxone/pvoutput/erweitert_1');
check('Loxone: v12 erweitert 6', $lo['felder']['v12']['topic'], 'loxone/pvoutput/erweitert_6');
check('Loxone: Quelle bleibt nach dem Speichern', $lo['felder']['v5']['quelle'], 'loxone');
check('Loxone: Eingang gehoert zu genau einem Feld', [PvoConfig::erlaubt('v5', 'loxone:v5'), PvoConfig::erlaubt('v6', 'loxone:v5'), PvoConfig::erlaubt('v2', 'loxone:v4')], [true, false, false]);
$lb = $lx(['v8' => ['auswahl' => 'loxone:v7'], 'v4' => ['auswahl' => 'loxone:v2'], 'v1' => ['auswahl' => 'loxone:v3_tag']]);
check('Loxone: Eingang eines anderen Feldes abgelehnt (v8)', $lb['felder']['v8']['quelle'], 'aus');
check('Loxone: Eingang eines anderen Feldes abgelehnt (v4)', $lb['felder']['v4']['topic'], '');
check('Loxone: Eingang eines anderen Feldes abgelehnt (v1)', [$lb['felder']['v1']['quelle'], $lb['felder']['v1']['modus']], ['aus', 'integral']);
check('Loxone: Liste v5', array_keys(PvoConfig::auswahl('v5')['Loxone (virtueller Ausgang)']), ['loxone:v5']);
check('Loxone: Liste v1 (Tageswert und Gesamtzaehler)', array_keys(PvoConfig::auswahl('v1')['Loxone (virtueller Ausgang)']), ['loxone:v1_tag', 'loxone:v1_gesamt']);
check('Loxone: Liste v10', array_keys(PvoConfig::auswahl('v10')['Loxone (virtueller Ausgang)']), ['loxone:v10']);
check('Loxone: Reihenfolge der Quellen', array_keys(PvoConfig::auswahl('v2')), ['Betriebsart', 'Fronius2Lox', 'Senec2Lox', 'Loxone (virtueller Ausgang)']);
$ein = PvoConfig::loxoneEingaenge();
check('Loxone: 14 Eingaenge', count($ein), 14);
check('Loxone: Topics eindeutig', count(array_unique(array_column($ein, 'topic'))), 14);
check('Loxone: Topics im Schema', count(array_filter($ein, fn($e) => !preg_match('#^loxone/pvoutput/[a-z0-9_]+$#', $e['topic']))), 0);
check('Loxone: nicht unter pvoutput/ (keine Rueckmeldung ans Gateway-Abo)', count(array_filter($ein, fn($e) => str_starts_with($e['topic'], 'pvoutput/'))), 0);
check('Loxone: alle Felder v1-v12 abgedeckt', array_values(array_unique(array_column($ein, 'feld'))), ['v1', 'v2', 'v3', 'v4', 'v5', 'v6', 'v7', 'v8', 'v9', 'v10', 'v11', 'v12']);
$cl = new Collector($lo);
$cl->onMessage('loxone/pvoutput/temperatur_c', '21,5 °C', 1000);
check('Loxone: Wert mit Komma und Einheit', $cl->wert('v5', 1000), 21.5);
$cl->onMessage('loxone/pvoutput/temperatur_c', 'kaputt', 1001);
check('Loxone: Unsinn aendert den Wert nicht', $cl->wert('v5', 1001), 21.5);

// ---------------------------------------------------------------- Unit: Sammler
$t0 = mktime(12, 0, 0, 6, 1, 2026);
$cfg = PvoConfig::defaults();
$cfg['felder']['v5']['topic'] = 'temp/aussen';
$cfg['felder']['v6']['topic'] = 'netz/spannung';
$col = new Collector($cfg);
check('Topics', $col->topics(), ['senec/live/pv_w', 'senec/live/haus_w', 'temp/aussen', 'netz/spannung']);
check('ohne Wert: build null', $col->build($t0), null);
$col->onMessage('senec/live/pv_w', 'abc', $t0);
check('Text ignoriert', $col->roh('senec/live/pv_w', $t0), null);
$col->onMessage('senec/live/pv_w', '3600', $t0);
$col->onMessage('senec/live/haus_w', '1200,5', $t0);
$col->onMessage('temp/aussen', '21.456', $t0);
$col->onMessage('netz/spannung', '231', $t0);
$col->onMessage('nicht/abonniert', '5', $t0);
$p = $col->build($t0);
check('v2', $p['v2'], '3600');
check('v4 Komma, gerundet', $p['v4'], '1201');
check('v5 Dezimal', $p['v5'], '21.46');
check('v6 ganzzahlig ohne Nachkomma', $p['v6'], '231');
check('v1 am Start 0', $p['v1'], '0');
// 30 Minuten mit 3600 W = 1800 Wh, Verbrauch 1200,5 W = 600 Wh
foreach ([600, 1200, 1800] as $dt) {        // Quelle meldet regelmaessig (Werte gelten max. 900 s)
    $col->onMessage('senec/live/pv_w', '3600', $t0 + $dt);
    $col->onMessage('senec/live/haus_w', '1200.5', $t0 + $dt);
}
$p = $col->build($t0 + 1800);
check('Tagesenergie Erzeugung', $p['v1'], '1800');
check('Tagesenergie Verbrauch', $p['v3'], '600');
// Leistungsaenderung: danach 600 s mit 1800 W = +300 Wh
$col->onMessage('senec/live/pv_w', '1800', $t0 + 1800);
$col->onMessage('senec/live/pv_w', '1800', $t0 + 2400);   // 600 s mit 1800 W
check('Treppenfunktion', $col->build($t0 + 2400)['v1'], '2100');
check('ohne flags kein c1', isset($p['c1']), false);
// Werte veralten: nach max_alter (900 s) zaehlt die Zeit nicht mehr mit, v2 wird nicht mehr gesendet
$late = $t0 + 2400 + 3600;
$pl = $col->build($late);
check('veraltet: v2 fehlt', isset($pl['v2']), false);
check('veraltet: v5 fehlt', isset($pl['v5']), false);
check('veraltet: Energie nur bis max_alter', $pl['v1'], (string)(int)round(2100 + 1800 * 900 / 3600));
// Mitternacht: neue Tagessumme
$next = mktime(0, 0, 5, 6, 2, 2026);
$col->onMessage('senec/live/pv_w', '3600', $next);
check('neuer Tag beginnt bei 0', $col->build($next)['v1'], '0');
// negative Leistung wird nicht gesendet, Temperatur schon
$col->onMessage('senec/live/pv_w', '-15', $next + 10);
$col->onMessage('temp/aussen', '-7.5', $next + 10);
$pn = $col->build($next + 10);
check('negative Leistung -> 0', $pn['v2'], '0');
check('negative Temperatur bleibt', $pn['v5'], '-7.5');
// Tagessumme ueberlebt Neustart (nur am selben Tag)
$st = $col->saveState($next + 3600);
$col2 = new Collector($cfg);
$col2->loadState($st, $next + 3601);
check('Zustand geladen', $col2->energie('v1') > 0, $col->energie('v1') > 0);
$col3 = new Collector($cfg);
$col3->loadState($st, $next + 86400 * 2);
check('Zustand von gestern verworfen', $col3->energie('v1'), 0.0);
// Gesamtzaehler: c1-Flag
$cz = PvoConfig::defaults();
$cz['felder']['v1'] = ['topic' => 'zaehler/erzeugt', 'faktor' => 1000.0, 'modus' => 'zaehler'];
$cz['felder']['v3']['modus'] = 'aus';
$colz = new Collector($cz);
$colz->onMessage('zaehler/erzeugt', '12.345', $t0);
$colz->onMessage('senec/live/pv_w', '500', $t0);
$pz = $colz->build($t0);
check('Zaehler v1 mit Faktor', $pz['v1'], '12345');
check('Zaehler: c1=2', $pz['c1'], '2');
check('v3 aus: nicht gesendet', isset($pz['v3']), false);
$cz['felder']['v3'] = ['topic' => 'zaehler/verbraucht', 'faktor' => 1.0, 'modus' => 'zaehler'];
$colz->configure($cz);
$colz->onMessage('zaehler/verbraucht', '999', $t0);
check('beide Zaehler: c1=1', $colz->build($t0)['c1'], '1');
$cz['felder']['v1']['modus'] = 'topic';
$colz->configure($cz);
check('v3 Zaehler allein: c1=3', $colz->build($t0)['c1'], '3');
// nur Zusatzwerte: nichts zu senden
$ce = PvoConfig::defaults();
$ce['felder']['v2']['topic'] = '';
$ce['felder']['v4']['topic'] = '';
$ce['felder']['v5']['topic'] = 'temp/aussen';
$cole = new Collector($ce);
$cole->onMessage('temp/aussen', '20', $t0);
check('nur Temperatur: build null', $cole->build($t0), null);

// ---------------------------------------------------------------- Unit: PVOutput-Client gegen Fake-Server
$php = PHP_BINARY;
$mqPort = 18831;
$pvoPort = 18901;
$pvoUrl = "http://127.0.0.1:$pvoPort/service/r2/";
$mqProc = startProc([$php, __DIR__ . '/fake_mqtt.php', (string)$mqPort, $dir]);
$pvoProc = startProc([$php, __DIR__ . '/fake_pvoutput.php', (string)$pvoPort, $dir]);
waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$pvoPort", $e, $es, 1) !== false, 5);
waitFor(fn() => @stream_socket_client("tcp://127.0.0.1:$mqPort", $e, $es, 1) !== false, 5);
$setMode = fn(string $m) => file_put_contents($dir . '/pvo_mode.txt', $m);
$pvoLog = fn() => (string)@file_get_contents($dir . '/pvo.log');

$cl = new PvoClient('GEHEIMKEY99', '4711', $pvoUrl);
$r = $cl->addStatus(['d' => '20260601', 't' => '12:00', 'v2' => '3600']);
check('addStatus ok', $r['ok'], true);
check('addStatus Antwort', $r['msg'], 'OK 200: Added Status');
$l = $pvoLog();
check('POST addstatus.jsp', str_contains($l, "POST\t/service/r2/addstatus.jsp\tGEHEIMKEY99\t4711\td=20260601&t=12%3A00&v2=3600"), true);
$setMode('401');
$r = $cl->addStatus(['d' => '20260601', 't' => '12:00', 'v2' => '1']);
check('401 nicht ok', $r['ok'], false);
check('401 kein Retry', $r['retry'], false);
check('401 Text', str_contains($r['msg'], 'API-Key oder System-ID ungültig'), true);
check('401 Text ohne Key', str_contains($r['msg'], 'GEHEIMKEY99'), false);
$setMode('403');
$r = $cl->addStatus(['d' => '20260601', 't' => '12:00', 'v2' => '1']);
check('403 Text vom Server', str_contains($r['msg'], 'Exceeded number of requests per hour'), true);
check('403 kein Retry', $r['retry'], false);
$setMode('500');
$r = $cl->addStatus(['d' => '20260601', 't' => '12:00', 'v2' => '1']);
check('500 Retry', $r['retry'], true);
check('500 HTML entfernt', $r['msg'], 'PVOutput antwortet 500: Server error');
$setMode('ok');
$r = $cl->check();
check('check ok', $r['ok'], true);
check('check: GET getstatus.jsp', str_contains($pvoLog(), "GET\t/service/r2/getstatus.jsp\tGEHEIMKEY99\t4711"), true);
$setMode('nostatus');
check('check: noch keine Daten = gueltig', $cl->check()['ok'], true);
$setMode('401');
check('check: 401', $cl->check()['ok'], false);
$setMode('ok');
$dead = new PvoClient('k', '1', 'http://127.0.0.1:1/service/r2/');
$r = $dead->addStatus(['v2' => '1']);
check('nicht erreichbar', $r['ok'], false);
check('nicht erreichbar: Retry', $r['retry'], true);
check('nicht erreichbar: Text', str_starts_with($r['msg'], 'PVOutput nicht erreichbar'), true);

// Logger maskiert Secrets
$lf = $dir . '/test.log';
$lg = new Logger($lf, 'Test', 'Test');
$lg->setLevel('debug');
$lg->addSecret('GEHEIMKEY99');
$lg->info('Key GEHEIMKEY99 benutzt');
check('Logger maskiert', str_contains((string)file_get_contents($lf), 'GEHEIMKEY99'), false);

// ---------------------------------------------------------------- Unit: MQTT-Client, Retained
$mq = new MqttClient('127.0.0.1', $mqPort, '', '', 'unit_test', 'unit/will');
$mq->subscribe('unit/#');
$mq->connect();
file_put_contents($dir . '/inject.txt', "unit/a\t1\tR\n");
usleep(600000);
$mq->loop();
check('retained standardmaessig ignoriert', $mq->takeMessages(), []);
$mq->acceptRetained(true);
file_put_contents($dir . '/inject.txt', "unit/b\t2\tR\n");
$got = [];
waitFor(function () use ($mq, &$got) {
    $mq->loop();
    $got = array_merge($got, $mq->takeMessages());
    return count($got) > 0;
}, 5);
check('retained mit acceptRetained', $got, [['unit/b', '2']]);
$mq->close();

// ---------------------------------------------------------------- Ende-zu-Ende: Dienst
$e2e = $dir . '/e2e';
@mkdir($e2e, 0777, true);
$conf = PvoConfig::load($e2e . '/pvoutput.json');
$conf['aktiv'] = true;
$conf['apikey'] = 'E2EKEYsecret77';
$conf['systemid'] = '4711';
$conf['mqtt'] = ['eigener_broker' => true, 'host' => '127.0.0.1', 'port' => $mqPort, 'benutzer' => '', 'passwort' => ''];
$conf['felder']['v5'] = ['quelle' => 'loxone', 'wert' => 'loxone:v5'] + $conf['felder']['v5'];       // Wert aus Loxone (virtueller Ausgang)
Util::jsonSave($e2e . '/pvoutput.json', $conf);
@unlink($dir . '/mqtt.log');
@unlink($dir . '/pvo.log');
$svc = startProc([$php, dirname(__DIR__) . '/lox2pvoutput/bin/pvoutputd.php'], [
    'PVO_DEV_DIR' => $e2e, 'PVO_DEV_LOGLEVEL' => 'debug', 'PVO_DEV_URL' => $pvoUrl, 'PVO_DEV_SLOT' => '3',
]);
$mlog = fn() => (string)@file_get_contents($dir . '/mqtt.log');
$lastStatus = function () use ($pvoLog): array {
    $res = [];
    foreach (explode("\n", $pvoLog()) as $line) {
        $f = explode("\t", $line);
        if (($f[0] ?? '') === 'POST' && str_contains($f[1] ?? '', 'addstatus')) {
            parse_str($f[4] ?? '', $res);
        }
    }
    return $res;
};
$posts = fn() => substr_count($pvoLog(), "POST\t");

check('Abo v2', waitFor(fn() => str_contains($mlog(), "SUB\tsenec/live/pv_w")), true);
$l = $mlog();
check('Abo v4', str_contains($l, "SUB\tsenec/live/haus_w"), true);
check('Abo v5 (Loxone-Eingang)', str_contains($l, "SUB\tloxone/pvoutput/temperatur_c"), true);
check('kein Abo fuer integrierte Energie', str_contains($l, "SUB\tsenec/live/erzeugung"), false);
check('Client-ID', str_contains($l, "CONNECT\tpvoutput_lox2pvoutput\tpvoutput/status/ok"), true);
file_put_contents($dir . '/inject.txt', "senec/live/pv_w\t3600\nsenec/live/haus_w\t1000\nloxone/pvoutput/temperatur_c\t21,5 °C\tR\n");
check('Upload erfolgt', waitFor(fn() => isset($lastStatus()['v2']), 15), true);
$s = $lastStatus();
check('v2', $s['v2'] ?? null, '3600');
check('v4', $s['v4'] ?? null, '1000');
check('v5 (retained angenommen)', $s['v5'] ?? null, '21.5');
check('Datum', (bool)preg_match('/^\d{8}$/', $s['d'] ?? ''), true);
check('Zeit', (bool)preg_match('/^\d\d:\d\d$/', $s['t'] ?? ''), true);
check('Energie berechnet (v1 vorhanden)', isset($s['v1']), true);
check('Energie berechnet (v1 >= 1 Wh bei 3600 W)', waitFor(fn() => (int)($lastStatus()['v1'] ?? 0) >= 1, 15), true);
check('Verbrauch berechnet (v3)', isset($s['v3']), true);
check('API-Key und System-ID im Header', str_contains($pvoLog(), "\tE2EKEYsecret77\t4711\t"), true);
check('upload/ok 1', waitFor(fn() => str_contains($mlog(), "pvoutput/upload/ok\t1")), true);
check('upload/meldung', str_contains($mlog(), "pvoutput/upload/meldung\tOK 200: Added Status"), true);
check('status/ok 1', waitFor(fn() => str_contains($mlog(), "pvoutput/status/ok\t1")), true);
check('Lebenszeichen', str_contains($mlog(), "pvoutput/status/zaehler\t1"), true);
check('Zeitstempel', (bool)preg_match('/pvoutput\/status\/ts\t\d{10}/', $mlog()), true);
check('Tagesenergie per MQTT', waitFor(fn() => (bool)preg_match('/pvoutput\/energie\/erzeugung_wh\t\d+/', $mlog()), 15), true);
$st = Util::jsonLoad($e2e . '/run/status.json');
check('status.json', $st['service'] ?? '', 'running');
check('status.json: Upload ok', $st['upload']['ok'] ?? null, true);
check('status.json: Feld v2', (float)($st['felder']['v2']['wert'] ?? 0), 3600.0);

// PVOutput lehnt ab (Limit): status/ok 0, Meldung, kein Dauerfeuer
$setMode('403');
$before = $posts();
check('403: status/ok 0', waitFor(fn() => str_contains($mlog(), "pvoutput/upload/ok\t0")), true);
check('403: Meldung', str_contains($mlog(), "pvoutput/upload/meldung\tPVOutput antwortet 403: Forbidden 403: Exceeded"), true);
check('403: Lebenszeichen laeuft weiter', waitFor(fn() => (bool)preg_match('/pvoutput\/status\/ok\t0/', $mlog())), true);
// Serverfehler 500: erneuter Versuch
$setMode('500');
check('500: neuer Versuch im Log', waitFor(fn() => str_contains((string)@file_get_contents($e2e . '/pvoutput.log'), 'neuer Versuch'), 15), true);
// Erholung
$setMode('ok');
@unlink($dir . '/mqtt.log');
check('Erholung: upload/ok 1', waitFor(fn() => str_contains($mlog(), "pvoutput/upload/ok\t1"), 90), true);
check('Erholung: status/ok 1', waitFor(fn() => str_contains($mlog(), "pvoutput/status/ok\t1")), true);

// Konfiguration im Betrieb aendern: neues Topic wird abonniert, Upload ausschalten
sleep(1);
$conf['felder']['v6'] = ['quelle' => 'topic', 'eigenes' => 'netz/spannung'] + $conf['felder']['v6'];
Util::jsonSave($e2e . '/pvoutput.json', $conf);
check('neues Topic abonniert', waitFor(fn() => str_contains($mlog(), "SUB\tnetz/spannung"), 10), true);
sleep(1);
$conf['felder']['v6'] = ['quelle' => 'fronius', 'wert' => 'fronius:wr_spannung_ac'] + $conf['felder']['v6'];      // Quelle aus der Auswahlliste
Util::jsonSave($e2e . '/pvoutput.json', $conf);
check('Quelle Fronius: Topic wird abonniert', waitFor(fn() => str_contains($mlog(), "SUB	fronius/wr1/wr/1/spannung_ac_v"), 10), true);
check('Quelle Fronius: Wildcard zum Erkennen von Name und Wechselrichter-Nummer abonniert', waitFor(fn() => str_contains($mlog(), "SUB	fronius/+/wr/+/leistung_ac_w"), 10), true);
// Der Wechselrichter meldet sich mit der Nummer 15: der Dienst erkennt sie und abonniert die Topics mit 15
file_put_contents($dir . '/inject.txt', "fronius/wr1/wr/15/leistung_ac_w\t6000\n");
check('Nummer 15 erkannt: neue Topics abonniert', waitFor(fn() => str_contains($mlog(), "SUB\tfronius/wr1/wr/15/spannung_ac_v"), 15), true);
check('Nummer 15 erkannt: Log', str_contains((string)@file_get_contents($e2e . '/pvoutput.log'), 'Wechselrichter mit der Nummer 15 erkannt'), true);
check('Nummer 15 erkannt: gespeichert (ueberlebt Neustart)', isset(Util::jsonLoad($e2e . '/entdeckt.json')['geraete']['wr1']['wr']['15']), true);
check('Name erkannt und gespeichert', isset(Util::jsonLoad($e2e . '/entdeckt.json')['namen']['wr1']), true);
check('Nummer 15 erkannt: im Status', waitFor(fn() => (Util::jsonLoad($e2e . '/run/status.json')['nummern']['wr'] ?? '') === '15', 10), true);
sleep(1);
$conf['aktiv'] = false;
Util::jsonSave($e2e . '/pvoutput.json', $conf);
sleep(2);
$n1 = $posts();
sleep(7);
check('aus: kein Upload mehr', $posts(), $n1);

stopProc($svc);
$logtext = (string)@file_get_contents($e2e . '/pvoutput.log');
check('Dienstlog vorhanden', str_contains($logtext, 'gestartet'), true);
check('API-Key nicht im Log', str_contains($logtext, 'E2EKEYsecret77'), false);
check('API-Key nicht per MQTT', str_contains($mlog(), 'E2EKEYsecret77'), false);
check('Tagesenergie gesichert', is_file($e2e . '/energie.json'), true);
check('Tagesenergie: Datum', Util::jsonLoad($e2e . '/energie.json')['date'] ?? '', date('Ymd'));

stopProc($pvoProc);
stopProc($mqProc);
// ---------------------------------------------------------------- Healthcheck (LoxBerry-Healthcheck-Seite)
$hcDir = $dir . '/hc';
@mkdir($hcDir . '/run', 0777, true);
$hc = function (string $arg, ?array $status) use ($php, $root, $hcDir): array {
    $f = $hcDir . '/run/status.json';
    @unlink($f);
    if ($status !== null) {
        file_put_contents($f, json_encode($status));
    }
    $p = proc_open([$php, $root . '/lox2pvoutput/bin/healthcheck', $arg], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), ['PVO_DEV_DIR' => $hcDir]));
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($p);
    return [$out, $err, $rc];
};
$basis = ['service' => 'running', 'time' => time(), 'mqtt' => 1, 'mqtt_error' => '', 'version' => AppVersion::CURRENT, 'aktiv' => true, 'zugang' => true,
    'upload' => ['ts' => mktime(12, 35, 0), 'ok' => true, 'meldung' => 'OK 200: Added Status']];
[$o, $e, $rc] = $hc('title', null);
check('Healthcheck title', trim($o), 'Lox2PVOutput: Dienst, MQTT-Broker und Upload zu pvoutput.org');
check('Healthcheck title: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck ohne Argument: keine Ausgabe', $hc('', null)[0], '');
$res = function (?array $st) use ($hc): array {
    [$o, $e, $rc] = $hc('check', $st);
    $j = json_decode($o, true);
    return [is_array($j) ? $j : ['status' => -1, 'result' => $o], $rc, $e];
};
[$j, $rc, $e] = $res(null);
check('Healthcheck: ohne Status = Fehler 3', $j['status'], 3);
check('Healthcheck: Exitcode 0, keine Fehlerausgabe', [$rc, $e], [0, '']);
check('Healthcheck: veralteter Status = Fehler 3', $res(['time' => time() - 120] + $basis)[0]['status'], 3);
$j = $res(['mqtt' => 0, 'mqtt_error' => 'Verbindung abgelehnt'] + $basis)[0];
check('Healthcheck: Broker weg = Warnung 4', $j['status'], 4);
check('Healthcheck: Broker-Grund im Text', str_contains($j['result'], 'Verbindung abgelehnt'), true);
check('Healthcheck: Upload ausgeschaltet = Info 6', $res(['aktiv' => false] + $basis)[0]['status'], 6);
$j = $res(['zugang' => false] + $basis)[0];
check('Healthcheck: Zugangsdaten fehlen = Warnung 4', $j['status'], 4);
check('Healthcheck: Zugangsdaten im Text', str_contains($j['result'], 'API-Key oder System-ID fehlt'), true);
$j = $res(['upload' => ['ts' => mktime(12, 35, 0), 'ok' => false, 'meldung' => 'PVOutput antwortet 403: Forbidden']] + $basis)[0];
check('Healthcheck: Upload fehlgeschlagen = Warnung 4', $j['status'], 4);
check('Healthcheck: Upload-Grund im Text', str_contains($j['result'], 'PVOutput antwortet 403: Forbidden'), true);
$j = $res(['upload' => ['ts' => 0, 'ok' => null, 'meldung' => '']] + $basis)[0];
check('Healthcheck: noch kein Upload = OK 5', $j['status'], 5);
check('Healthcheck: noch kein Upload im Text', str_contains($j['result'], 'noch kein Upload'), true);
$j = $res($basis)[0];
check('Healthcheck: alles gut = OK 5', $j['status'], 5);
check('Healthcheck: Text nennt Uhrzeit und Version', $j['result'], 'Letzter Upload um 12:35 Uhr erfolgreich, Version ' . AppVersion::CURRENT);
check('Healthcheck: ohne Logdatei kein logfile', isset($j['logfile']), false);
file_put_contents($hcDir . '/pvoutput.log', "x\n");
check('Healthcheck: mit Logdatei der Pfad', $res($basis)[0]['logfile'] ?? null, $hcDir . '/pvoutput.log');
check('Healthcheck: Ausgabe ist genau eine JSON-Zeile', substr_count(rtrim($hc('check', $basis)[0], "\n"), "\n"), 0);

check('plugin.cfg: WEBSITE gesetzt (sonst ist der Dokumentation-Knopf der Plugin-Verwaltung ausgegraut)', str_contains((string)file_get_contents($root . '/lox2pvoutput/plugin.cfg'), "
WEBSITE=https://github.com/ricten/Lox2PVOutput
"), true);

// ---------------------------------------------------------------- Weboberflaeche mit Attrappen der LoxBerry-Bibliotheken
// Rendert jeden Reiter (ohne echtes LoxBerry): findet undefinierte Variablen, Syntax- und Laufzeitfehler und prueft Menueleiste,
// Gateway-Abo und UDP-Port. Nicht pruefbar: das echte Aussehen in LoxBerry.
$uiDir = $dir . '/ui';
foreach (['config', 'log', 'data', 'sys', 'dev'] as $sub) {
    @mkdir("$uiDir/$sub", 0777, true);
}
@mkdir("$uiDir/sess", 0777, true);
$uiPage = function (string $tab, array $env = []) use ($php, $root, $uiDir): array {
    $e = array_merge(getenv(), [
        'STUB_INDEX' => $root . '/lox2pvoutput/webfrontend/htmlauth/index.php', 'STUB_BIN' => $root . '/lox2pvoutput/bin', 'STUB_CONFIG' => "$uiDir/config",
        'STUB_LOG' => "$uiDir/log", 'STUB_DATA' => "$uiDir/data", 'STUB_SYSCONFIG' => "$uiDir/sys", 'PVO_DEV_DIR' => "$uiDir/dev",
    ], $env);
    $cmd = [$php, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'include_path=' . $root . '/tests/stubs', '-d', 'session.save_path=' . "$uiDir/sess",
        $root . '/tests/ui_page.php', $tab];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$out, $err];
};
$bad = fn(string $o, string $e) => (bool)preg_match('/(Warning|Notice|Fatal error|Deprecated|Parse error|Uncaught)/', $o . $e);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 2, 'Udpinport' => '12345']]));
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'shellies/#']]]));
file_put_contents("$uiDir/log/test.log", "2026-01-01 <INFO> Testzeile\n");
foreach (['einstellungen', 'mqtt', 'loxone', 'test', 'logs'] as $tab) {
    [$o, $e] = $uiPage($tab, ['STUB_UDP' => '12345']);
    check("UI $tab: ohne PHP-Warnungen und -Fehler", [$bad($o, $e), $e], [false, '']);
    check("UI $tab: Kopf und Fuss", str_contains($o, '<!--HEADER:') && str_contains($o, '<!--FOOTER-->'), true);
    preg_match('/<!--NAVBAR:(.*?)-->/', $o, $m);
    $nav = json_decode($m[1] ?? '[]', true) ?: [];
    check("UI $tab: Menueleiste mit 5 Eintraegen", count($nav), 5);
    check("UI $tab: genau ein aktiver Eintrag, und zwar dieser", array_values(array_filter($nav, fn($n) => !empty($n['active'])))[0]['URL'] ?? null, '?tab=' . $tab);
    check("UI $tab: nur ein aktiver Eintrag", count(array_filter($nav, fn($n) => !empty($n['active']))), 1);
    check("UI $tab: kein eigener Tab-Block mehr", str_contains($o, '-tabs"'), false);
    check("UI $tab: Design System (nojqm gesetzt, lb-content, kein data-role/pvo-chk)", [str_contains($o, '<!--NOJQM:1-->'), str_contains($o, 'class="lb-content'), str_contains($o, 'data-role='), str_contains($o, 'pvo-chk')], [true, true, false, false]);
}
// Geraete aus den Topics: ohne Funde Eingabefelder, mit Funden Auswahllisten
@unlink("$uiDir/dev/entdeckt.json");
[$o, $e] = $uiPage('einstellungen');
check('UI Einstellungen: Formular im Design System (lb-input, lb-select, lb-toggle, lb-btn, lb-table)', [str_contains($o, 'class="lb-input"'), str_contains($o, 'class="lb-select"'), str_contains($o, 'class="lb-toggle"'), str_contains($o, 'lb-btn lb-btn-primary'), str_contains($o, 'class="lb-table"')], [true, true, true, true, true]);
check('UI Einstellungen: Felder behalten ihre Namen (token, aktiv, apikey, felder)', [str_contains($o, 'name="token"'), str_contains($o, 'name="aktiv"'), str_contains($o, 'name="apikey"'), str_contains($o, 'name="felder[v1][auswahl]"'), str_contains($o, 'name="felder[v1][faktor]"')], [true, true, true, true, true]);
check('UI Einstellungen: ohne erkannte Geraete Eingabefelder', [$bad($o, $e), str_contains($o, '<input class="lb-input" type="text" name="quellen[fronius_name]"'), str_contains($o, '<input class="lb-input" type="text" name="quellen[fronius_wr]"')], [false, true, true]);
file_put_contents("$uiDir/dev/entdeckt.json", json_encode(['namen' => ['symo' => 1000, 'garage' => 1000], 'geraete' => ['wr1' => ['wr' => ['1' => 1000]], 'symo' => ['wr' => ['15' => 1000]]]]));
[$o, $e] = $uiPage('einstellungen');
check('UI Einstellungen: Name als Auswahl aus den erkannten Topics', [$bad($o, $e), str_contains($o, '<select class="lb-select" name="quellen[fronius_name]"'), str_contains($o, '<option value="symo"'), str_contains($o, '<option value="garage"'), str_contains($o, 'wr1 (nicht erkannt)')], [false, true, true, true, true]);
check('UI Einstellungen: Nummer als Auswahl mit automatisch', [str_contains($o, '<select class="lb-select" name="quellen[fronius_wr]"'), str_contains($o, 'automatisch (aktuell 1)'), str_contains($o, '<option value="15">15</option>')], [true, true, false]);
@unlink("$uiDir/dev/entdeckt.json");
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo fehlt, mit Anleitung', str_contains($o, 'Gateway V2: Das Abo „pvoutput/#“ fehlt'), true);
check('UI mqtt: nichts abonniert = rotes Feld', str_contains($o, 'gw-abo-fehler') && !str_contains($o, 'gw-abo-ok'), true);
check('UI mqtt: rotes Feld ohne Topic-Liste', str_contains($o, 'gw-abo-fehler') && !str_contains($o, '<ul style="margin:4px 0 0 18px'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'pvoutput/#']]]));
[$o] = $uiPage('mqtt', ['STUB_UDP' => '12345']);
check('UI mqtt: Gateway V2, Abo eingetragen', str_contains($o, 'Gateway V2: eingetragen (pvoutput/#)'), true);
check('UI mqtt: vollstaendig abonniert = gruenes Feld mit Topic-Liste', str_contains($o, 'gw-abo-ok') && str_contains($o, '<li><code>pvoutput/#</code></li>') && !str_contains($o, 'gw-abo-fehler'), true);
file_put_contents("$uiDir/sys/subscriptions.json", json_encode(['Subscriptions' => [['Id' => 'pvoutput/status/#']]]));
[$o] = $uiPage('mqtt');
check('UI mqtt: nur ein Teil abonniert = gruen (nicht rot)', str_contains($o, 'gw-abo-ok') && !str_contains($o, 'gw-abo-fehler'), true);
check('UI mqtt: Teil-Abo: Hinweis und Topic aufgelistet', str_contains($o, 'teilweise abonniert (pvoutput/status/#)') && str_contains($o, '<li><code>pvoutput/status/#</code></li>'), true);
file_put_contents("$uiDir/sys/general.json", json_encode(['Mqtt' => ['Gatewayversion' => 1]]));
file_put_contents("$uiDir/config/mqtt_subscriptions.cfg", "pvoutput/#\n");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo in der Datei = gruen', str_contains($o, 'Gateway V1: eingetragen (pvoutput/#)') && str_contains($o, 'gw-abo-ok'), true);
@unlink("$uiDir/config/mqtt_subscriptions.cfg");
[$o] = $uiPage('mqtt');
check('UI mqtt: Gateway V1, Abo-Datei fehlt', str_contains($o, 'Gateway V1: Das Abo'), true);
[$o] = $uiPage('loxone', ['STUB_UDP' => '12345']);
preg_match('#/dev/udp/[^/]+/(\d+)#', $o, $um);
check('UI loxone: UDP-Port aus der Konfiguration statt fest 11884', $um[1] ?? null, '12345');

[$o] = $uiPage('logs');
check('UI logs: zeigt die Logdatei', str_contains($o, 'Testzeile'), true);

echo "\n$count Pruefungen, $fails Fehler\n";
exit($fails ? 1 : 0);
