<?php
/** PVOutput-Dienst: sammelt Werte aus MQTT und sendet sie in festem Takt an pvoutput.org. */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
if (!getenv('PVO_DEV_DIR')) {
    require_once 'loxberry_system.php';
    require_once 'loxberry_io.php';
    require_once 'loxberry_log.php';
}
require_once __DIR__ . '/lib/bootstrap.php';

const T = 'pvoutput';    // Topic-Wurzel

$paths = Paths::detect();

// Nur eine Instanz
$lock = fopen($paths->lockFile(), 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}
file_put_contents($paths->pidFile(), (string)getmypid());

$log = new Logger($paths->logfile, 'Dienst', 'PVOutput-Dienst');
/** Loglevel aus der LoxBerry-Pluginverwaltung (im Test PVO_DEV_LOGLEVEL). Wird im Betrieb regelmaessig neu gelesen. */
function applyLogLevel(Logger $log): void
{
    if (getenv('PVO_DEV_DIR')) {
        $log->setLevel(getenv('PVO_DEV_LOGLEVEL') ?: 'debug');
    } elseif (class_exists('LBSystem') && method_exists('LBSystem', 'pluginloglevel')) {
        $lvl = (int)LBSystem::pluginloglevel();
        if ($lvl > 0) {
            $log->setLoxberryLevel($lvl);
        }
    }
}
applyLogLevel($log);
$logLevelCheck = 0;
register_shutdown_function(function () use ($log) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $log->error('Dienst abgestuerzt: ' . $e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')');
    }
});
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () use (&$running) {
        $running = false;
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

/** Broker-Daten: eigener Broker aus den Einstellungen, sonst LoxBerry-Konfiguration; im Test PVO_DEV_MQTT=host:port. */
function brokerDetails(Logger $log, array $pvo): ?array
{
    $dev = getenv('PVO_DEV_MQTT');
    if ($dev) {
        [$h, $p] = explode(':', $dev, 2) + [1 => '1883'];
        return [$h, (int)$p, '', ''];
    }
    $own = PvoConfig::ownBroker($pvo);
    if ($own !== null) {
        return $own;
    }
    if (!function_exists('mqtt_connectiondetails')) {
        $log->error('LoxBerry-Funktion mqtt_connectiondetails() nicht verfuegbar', 'mqtt-details');
        return null;
    }
    $d = mqtt_connectiondetails();     // ueberschreibt die globale Variable $cfg - die Dienstkonfiguration heisst deshalb $pvo
    $host = is_array($d) ? (string)($d['brokerhost'] ?? '') : '';
    $port = is_array($d) ? (int)($d['brokerport'] ?? 0) : 0;
    if (($host === '' || $port === 0) && is_array($d) && !empty($d['brokeraddress']) && str_contains((string)$d['brokeraddress'], ':')) {
        [$h2, $p2] = explode(':', (string)$d['brokeraddress'], 2);
        $host = $host !== '' ? $host : $h2;
        $port = $port ?: (int)$p2;
    }
    if ($host === '') {
        $log->error('Keine Broker-Daten in der LoxBerry-Konfiguration (MQTT eingerichtet?)', 'mqtt-details');
        return null;
    }
    return [$host, $port ?: 1883, (string)($d['brokeruser'] ?? ''), (string)($d['brokerpass'] ?? '')];
}

/** Kurztext der gesendeten Werte fuer das Log (ohne Zugangsdaten). */
function paramText(array $p): string
{
    $o = [];
    foreach ($p as $k => $v) {
        $o[] = "$k=$v";
    }
    return implode(' ', $o);
}

// ------------------------------------------------------------------ Hauptschleife
// Erkannte Geraetenummern (Art => [Nummer => letzte Sichtung]); der Wechselrichter heisst bei Fronius nicht immer 1
$entdeckt = Util::jsonLoad($paths->entdeckt);
$entdecktSaved = time();
$pvo = PvoConfig::mitEntdeckten(PvoConfig::load($paths->config), $entdeckt);
$col = new Collector($pvo);
$col->loadState(Util::jsonLoad($paths->energie), time());
$cfgMtime = -1;
$mqtt = null;
$mqttErr = '';
$mqttBackoff = 2;
$mqttNext = 0;
$mqttSince = 0;
$subTopics = [];
$zaehler = 0;
$lastTick = 0;
$lastSec = 0;
$lastEnergy = 0;
$lastStatus = 0;
$lastSlot = null;
$pending = null;                       // ['params'=>..., 'tries'=>n, 'next'=>ts]
$upload = ['ts' => 0, 'ok' => null, 'meldung' => '', 'ok_anzahl' => 0, 'fehler_anzahl' => 0, 'gesendet' => []];
$started = time();
$log->info('PVOutput-Dienst ' . AppVersion::CURRENT . ' gestartet (PID ' . getmypid() . ')');

while ($running) {
    try {
        $now = time();
        if ($now >= $logLevelCheck) {      // Loglevel-Aenderung der Pluginverwaltung ohne Neustart uebernehmen
            $logLevelCheck = $now + 30;
            applyLogLevel($log);
        }

        // Konfiguration neu laden, wenn sich die Datei geaendert hat
        clearstatcache(true, $paths->config);
        $mt = is_file($paths->config) ? (int)filemtime($paths->config) : 0;
        if ($mt !== $cfgMtime) {
            $cfgMtime = $mt;
            $oldMqtt = $pvo['mqtt'];
            $pvo = PvoConfig::mitEntdeckten(PvoConfig::load($paths->config), $entdeckt);
            $col->configure($pvo);
            $log->addSecret($pvo['apikey']);
            $log->addSecret((string)$pvo['mqtt']['passwort']);
            if ($mqtt !== null && ($oldMqtt != $pvo['mqtt'] || $subTopics != $col->topics())) {   // Broker oder Topics geaendert: neu verbinden
                $mqtt->close();
                $mqtt = null;
                $mqttNext = 0;
                $mqttBackoff = 2;
            }
            $log->info('Konfiguration geladen: Upload ' . ($pvo['aktiv'] ? 'an' : 'aus') . ', ' . count($col->topics()) . ' Topics, alle ' . $pvo['intervall'] . ' min');
            if ($pvo['aktiv'] && !PvoConfig::zugangOk($pvo)) {
                $log->warn('API-Key oder System-ID fehlt - es wird nichts gesendet', 'no-access');
            }
        }

        // MQTT verbinden (mit wachsendem Abstand bei Fehlern)
        if (($mqtt === null || !$mqtt->connected()) && $now >= $mqttNext) {
            $bd = brokerDetails($log, $pvo);
            if ($bd === null) {
                $mqttErr = 'Keine Broker-Daten';
                $mqttNext = $now + 30;
            } else {
                $mqtt = new MqttClient($bd[0], $bd[1], $bd[2], $bd[3], 'pvoutput_' . $paths->folder, T . '/status/ok');
                $log->addSecret($bd[3]);
                $mqtt->acceptRetained(true);        // reine Messwerte: auch den zuletzt gemerkten Wert annehmen
                $subTopics = $col->topics();
                foreach ($subTopics as $t) {
                    $mqtt->subscribe($t);
                }
                foreach (PvoConfig::entdeckungsFilter() as $t) {      // Geraetenummern erkennen (Wildcard)
                    $mqtt->subscribe($t);
                }
                if ($mqtt->connect()) {
                    $log->info("MQTT verbunden ({$bd[0]}:{$bd[1]}), " . count($subTopics) . ' Topics abonniert');
                    $mqttErr = '';
                    $mqttSince = $now;
                    $mqttBackoff = 2;
                } else {
                    $mqttErr = $mqtt->lastError();
                    $log->error('MQTT: ' . $mqttErr, 'mqtt-connect');
                    $mqttNext = $now + $mqttBackoff;
                    $mqttBackoff = min(60, $mqttBackoff * 2);
                }
            }
        }
        if ($mqtt !== null && $mqtt->connected()) {
            if (!$mqtt->loop()) {
                $mqttErr = $mqtt->lastError();
                $log->warn('MQTT getrennt: ' . $mqttErr, 'mqtt-lost');
            }
            foreach ($mqtt->takeMessages() as [$t, $p]) {
                $geraet = PvoConfig::geraetAusTopic($t);
                if ($geraet !== null) {
                    [$gname, $art, $nr] = $geraet;
                    $neu = !isset($entdeckt['geraete'][$gname][$art][$nr]) || !isset($entdeckt['namen'][$gname]);
                    $entdeckt['namen'][$gname] = $now;
                    $entdeckt['geraete'][$gname][$art][$nr] = $now;
                    if ($neu || $now - $entdecktSaved >= 600) {       // nur bei Neuem oder alle 10 Minuten speichern
                        $entdecktSaved = $now;
                        Util::jsonSave($paths->entdeckt, $entdeckt);
                    }
                    if ($neu && $gname === $pvo['quellen']['fronius_name']) {
                        $alt = $pvo['quellen']['nr'] ?? [];
                        $pvo = PvoConfig::mitEntdeckten($pvo, $entdeckt);
                        if (($pvo['quellen']['nr'][$art] ?? '') !== ($alt[$art] ?? '')) {
                            $log->info('Fronius2Lox: ' . PvoConfig::NUMMERN[$art][0] . ' mit der Nummer ' . $nr . ' erkannt');
                            $col->configure($pvo);
                            if ($subTopics != $col->topics()) {          // Topics haben sich geaendert: neu abonnieren
                                $mqtt->close();
                                $mqtt = null;
                                $mqttNext = 0;
                                $mqttBackoff = 2;
                                break;
                            }
                        }
                    }
                }
                $col->onMessage($t, $p, $now);
            }
        }

        if ($now !== $lastSec) {
            $lastSec = $now;
            $col->advanceAll($now);

            // Takt: einmal je PVOutput-Intervall zur vollen Zeit (5/10/15 min), im Test PVO_DEV_SLOT Sekunden
            $slotLen = (int)(getenv('PVO_DEV_SLOT') ?: $pvo['intervall'] * 60);
            $slot = intdiv($now, $slotLen);
            if ($lastSlot === null) {
                $lastSlot = $slot;         // nach dem Start erst zum naechsten Zeitpunkt senden: Werte sammeln
            }
            if ($slot !== $lastSlot) {
                $lastSlot = $slot;
                $slotTime = $slot * $slotLen;
                Util::jsonSave($paths->energie, $col->saveState($now));
                if ($pvo['aktiv'] && PvoConfig::zugangOk($pvo)) {
                    $params = $col->build($now);
                    if ($params === null) {
                        $upload['ok'] = false;
                        $upload['meldung'] = 'Keine aktuellen Werte (v1-v4) - nichts gesendet';
                        $upload['fehler_anzahl']++;
                        $log->warn($upload['meldung'], 'no-values');
                    } else {
                        $pending = ['params' => ['d' => date('Ymd', $slotTime), 't' => date('H:i', $slotTime)] + $params, 'tries' => 0, 'next' => $now];
                    }
                }
            }
            if ($pending !== null && $now >= $pending['next']) {
                $client = new PvoClient($pvo['apikey'], $pvo['systemid']);
                $res = $client->addStatus($pending['params']);
                $pending['tries']++;
                $upload['ts'] = $now;
                $upload['ok'] = $res['ok'];
                $upload['meldung'] = $res['msg'];
                if ($res['ok']) {
                    $upload['ok_anzahl']++;
                    $upload['gesendet'] = $pending['params'];
                    $log->info('An PVOutput gesendet: ' . paramText($pending['params']) . ' (' . $res['msg'] . ')');
                    $pending = null;
                } elseif ($res['retry'] && $pending['tries'] < 3) {
                    $log->warn('PVOutput: ' . $res['msg'] . ' - neuer Versuch in 60 s', 'upload-retry');
                    $pending['next'] = $now + 60;
                } else {
                    $upload['fehler_anzahl']++;
                    $log->error('PVOutput: ' . $res['msg'], 'upload-fail');
                    $pending = null;
                }
                if ($mqtt !== null && $mqtt->connected() && $pending === null) {
                    $mqtt->publish(T . '/upload/ok', $res['ok'] ? '1' : '0');
                    $mqtt->publish(T . '/upload/ts', (string)$now);
                    $mqtt->publish(T . '/upload/meldung', $res['msg']);
                }
            }
        }

        // Lebenszeichen alle 10 s, berechnete Tagesenergie jede Minute
        $zugangFehlt = $pvo['aktiv'] && !PvoConfig::zugangOk($pvo);
        $ok = ($zugangFehlt || ($pvo['aktiv'] && $upload['ok'] === false)) ? 0 : 1;
        $dev = (bool)getenv('PVO_DEV_SLOT');       // im Test schneller takten
        if ($now - $lastTick >= ($dev ? 3 : 10)) {
            $lastTick = $now;
            $zaehler = ($zaehler + 1) % 1000;
            if ($mqtt !== null && $mqtt->connected()) {
                $mqtt->publish(T . '/status/ok', (string)$ok);
                $mqtt->publish(T . '/status/zaehler', (string)$zaehler);
                $mqtt->publish(T . '/status/ts', (string)$now);
                if ($now - $lastEnergy >= ($dev ? 3 : 60)) {
                    $lastEnergy = $now;
                    foreach (['v1' => 'erzeugung_wh', 'v3' => 'verbrauch_wh'] as $k => $name) {
                        if (($pvo['felder'][$k]['modus'] ?? '') === 'integral' && ($v = $col->wert($k, $now)) !== null) {
                            $mqtt->publish(T . "/energie/$name", (string)(int)round($v));
                        }
                    }
                }
            }
            $log->rotateCheck();
        }

        if ($now !== $lastStatus) {      // Status hoechstens einmal pro Sekunde (liegt im RAM, /dev/shm)
            $lastStatus = $now;
            $slotLen = (int)(getenv('PVO_DEV_SLOT') ?: $pvo['intervall'] * 60);
            Util::jsonSave($paths->statusFile(), [
                'service' => 'running', 'time' => $now, 'started' => $started,
                'ok' => $ok, 'zaehler' => $zaehler, 'ts' => $lastTick,
                'mqtt' => ($mqtt !== null && $mqtt->connected()) ? 1 : 0, 'mqtt_error' => $mqttErr, 'mqtt_seit' => $mqttSince,
                'aktiv' => $pvo['aktiv'], 'zugang' => PvoConfig::zugangOk($pvo), 'upload' => $upload, 'naechster' => ($lastSlot !== null ? ($lastSlot + 1) * $slotLen : 0),
                'felder' => $col->uebersicht($now), 'version' => AppVersion::CURRENT,
                'nummern' => $pvo['quellen']['nr'] ?? [], 'entdeckt' => $entdeckt,
            ]);
        }
    } catch (Throwable $e) {
        $log->error('Fehler in der Hauptschleife: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', 'loop');
    }
    usleep(200000);
}

Util::jsonSave($paths->energie, $col->saveState(time()));
if ($mqtt !== null && $mqtt->connected()) {
    $mqtt->publish(T . '/status/ok', '0');
    $mqtt->close();
}
$log->info('PVOutput-Dienst beendet');
$log->end();
@unlink($paths->pidFile());
