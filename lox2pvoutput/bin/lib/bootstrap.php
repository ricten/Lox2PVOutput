<?php
/**
 * Gemeinsamer Start fuer Dienst und Weboberflaeche: Klassen, Zeitzone und Pfade.
 * Ausserhalb von LoxBerry (Entwicklung/Tests) greift PVO_DEV_DIR als Basisverzeichnis.
 * Util, Logger und MqttClient kommen aus ../common/php (build.sh kopiert sie nach bin/lib).
 */
date_default_timezone_set('Europe/Berlin');     // PHP auf dem LoxBerry laeuft auf UTC

foreach (['Util', 'Logger', 'MqttClient', 'GatewayInfo', 'PvoConfig', 'PvoClient', 'Collector', 'AppVersion'] as $cls) {
    // im ZIP liegt alles in bin/lib; im Quellbaum kommen die gemeinsamen Klassen aus ../common/php
    $f = __DIR__ . '/' . $cls . '.php';
    require_once is_file($f) ? $f : __DIR__ . '/../../../../common/php/' . $cls . '.php';
}

final class Paths
{
    public string $folder;
    public string $config;     // pvoutput.json (enthaelt den API-Key, Rechte 0600)
    public string $energie;    // energie.json: Tagesenergie der Berechnung aus Leistung (ueberlebt Neustarts)
    public string $entdeckt;   // entdeckt.json: erkannte Geraetenummern aus den Fronius2Lox-Topics (z. B. Wechselrichter 15)
    public string $logfile;
    public string $run;        // /dev/shm/<folder>
    public string $bin;

    public static function detect(): Paths
    {
        $p = new Paths();
        $dev = getenv('PVO_DEV_DIR');
        if ($dev) {
            $p->folder = getenv('PVO_DEV_FOLDER') ?: 'lox2pvoutput';
            $p->config = $dev . '/pvoutput.json';
            $p->energie = $dev . '/energie.json';
            $p->entdeckt = $dev . '/entdeckt.json';
            $p->logfile = $dev . '/pvoutput.log';
            $p->run = getenv('PVO_DEV_RUN') ?: $dev . '/run';
            $p->bin = dirname(__DIR__);
        } else {
            require_once 'loxberry_system.php';
            $p->folder = LBPPLUGINDIR;
            $p->config = LBPCONFIGDIR . '/pvoutput.json';
            $p->energie = LBPDATADIR . '/energie.json';
            $p->entdeckt = LBPDATADIR . '/entdeckt.json';
            $p->logfile = LBPLOGDIR . '/pvoutput.log';
            $p->run = '/dev/shm/' . LBPPLUGINDIR;
            $p->bin = LBPBINDIR;
        }
        if (!is_dir($p->run)) {
            @mkdir($p->run, 0775, true);
        }
        return $p;
    }

    public function statusFile(): string
    {
        return $this->run . '/status.json';
    }

    public function pidFile(): string
    {
        return $this->run . '/pvoutput.pid';
    }

    public function lockFile(): string
    {
        return $this->run . '/run.lock';
    }
}
