<?php
/**
 * Sammelt die Messwerte aus MQTT und baut daraus den PVOutput-Status.
 *
 * - Werte gelten nur, solange sie nicht aelter als max_alter Sekunden sind.
 * - Tagesenergie (v1/v3) kann aus der Leistung (v2/v4) berechnet werden: der letzte Leistungswert gilt bis zum
 *   naechsten (Treppenfunktion). Zeiten ohne frischen Wert (laenger als max_alter) zaehlen nicht mit.
 *   Um Mitternacht (Europe/Berlin) beginnt die Summe bei 0.
 * - Leistung und Energie werden nie negativ gesendet.
 */
final class Collector
{
    /** Energiefeld => zugehoeriges Leistungsfeld */
    private const array LEISTUNG = ['v1' => 'v2', 'v3' => 'v4'];

    /** @var array<string,array{0:float,1:int}> topic => [roher Wert, Zeitpunkt] */
    private array $vals = [];
    /** @var array<string,array{date:string,wh:float,p:?float,t:?int,msg:?int}> */
    private array $int = [];
    private array $cfg;

    public function __construct(array $cfg)
    {
        $this->configure($cfg);
    }

    /** Konfiguration (neu) setzen; gesammelte Werte und Tagessummen bleiben erhalten. */
    public function configure(array $cfg): void
    {
        $this->cfg = $cfg;
        foreach (self::LEISTUNG as $e => $p) {
            $this->int[$e] ??= ['date' => '', 'wh' => 0.0, 'p' => null, 't' => null, 'msg' => null];
        }
    }

    /**
     * Zahl aus einer MQTT-Nachricht. Loxone sendet je nach Formatierung ein Komma und eine Einheit ("21,5 °C", "3.2 kW"):
     * Komma wird Punkt, eine angehaengte Einheit wird ignoriert (umgerechnet wird nicht, dafuer gibt es den Faktor).
     */
    public static function parse(string $payload): ?float
    {
        $s = trim($payload);
        if (!preg_match('/^([-+]?\d+(?:[.,]\d+)?(?:[eE][-+]?\d+)?)\s*(?:[A-Za-z\x{00B5}\x{00B0}%\x{00B2}\x{00B3}\/]+)?$/u', $s, $m)) {
            return null;
        }
        $v = (float)str_replace(',', '.', $m[1]);
        return is_finite($v) ? $v : null;
    }

    /** Alle benoetigten Topics (ohne Doppelte). */
    public function topics(): array
    {
        return array_values(array_unique(PvoConfig::topics($this->cfg)));
    }

    public function onMessage(string $topic, string $payload, int $now): void
    {
        $v = self::parse($payload);
        if ($v === null) {
            return;
        }
        $this->vals[$topic] = [$v, $now];
        foreach ($this->cfg['felder'] as $k => $f) {
            if ($f['topic'] !== $topic) {
                continue;
            }
            foreach (self::LEISTUNG as $e => $p) {
                if ($p === $k && ($this->cfg['felder'][$e]['modus'] ?? '') === 'integral') {
                    $this->advance($e, $now);
                    $this->int[$e]['p'] = max(0.0, $v * $f['faktor']);
                    $this->int[$e]['msg'] = $now;
                }
            }
        }
    }

    /** Tagessumme der berechneten Energie bis `now` fortschreiben (regelmaessig aufrufen). */
    public function advance(string $e, int $now): void
    {
        $s = $this->int[$e];
        $today = date('Ymd', $now);
        if ($s['date'] !== $today) {             // neuer Tag: bei 0 beginnen
            $s['date'] = $today;
            $s['wh'] = 0.0;
        } elseif ($s['p'] !== null && $s['t'] !== null && $s['msg'] !== null) {
            $bis = min($now, $s['msg'] + (int)$this->cfg['max_alter']);
            if ($bis > $s['t']) {
                $s['wh'] += $s['p'] * ($bis - $s['t']) / 3600;
            }
        }
        $s['t'] = $now;
        $this->int[$e] = $s;
    }

    public function advanceAll(int $now): void
    {
        foreach (array_keys(self::LEISTUNG) as $e) {
            if (($this->cfg['felder'][$e]['modus'] ?? '') === 'integral') {
                $this->advance($e, $now);
            }
        }
    }

    /** Berechnete Tagesenergie in Wh. */
    public function energie(string $e): float
    {
        return $this->int[$e]['wh'];
    }

    /** Tagessummen sichern bzw. nach einem Neustart wiederherstellen (nur vom selben Tag). */
    public function saveState(int $now): array
    {
        return ['date' => date('Ymd', $now), 'v1' => round($this->int['v1']['wh'], 3), 'v3' => round($this->int['v3']['wh'], 3)];
    }

    public function loadState(array $s, int $now): void
    {
        if (($s['date'] ?? '') !== date('Ymd', $now)) {
            return;
        }
        foreach (['v1', 'v3'] as $e) {
            $wh = $s[$e] ?? 0;
            if (is_numeric($wh) && $wh > 0) {
                $this->int[$e]['wh'] = (float)$wh;
                $this->int[$e]['date'] = $s['date'];
                $this->int[$e]['t'] = $now;
            }
        }
    }

    /** Roher Wert und Alter eines Topics. @return array{0:float,1:int}|null */
    public function roh(string $topic, int $now): ?array
    {
        if (!isset($this->vals[$topic])) {
            return null;
        }
        return [$this->vals[$topic][0], $now - $this->vals[$topic][1]];
    }

    /** Aktueller, umgerechneter Wert eines Feldes (null: kein frischer Wert / Feld unbenutzt). */
    public function wert(string $k, int $now): ?float
    {
        if (!PvoConfig::feldAktiv($this->cfg, $k)) {
            return null;
        }
        $f = $this->cfg['felder'][$k];
        $art = PvoConfig::FELDER[$k][2];
        if (($f['modus'] ?? '') === 'integral') {
            $s = $this->int[$k];
            if ($s['msg'] === null && $s['wh'] <= 0) {
                return null;                 // noch nie ein Leistungswert gesehen
            }
            return $s['wh'];
        }
        $r = $this->roh($f['topic'], $now);
        if ($r === null || $r[1] > (int)$this->cfg['max_alter']) {
            return null;
        }
        $v = $r[0] * $f['faktor'];
        return $art === 'wert' ? $v : max(0.0, $v);
    }

    /**
     * Parameter fuer addstatus.jsp (ohne d und t). null, wenn keiner der Werte v1-v4 vorliegt.
     * @return array<string,string>|null
     */
    public function build(int $now): ?array
    {
        $this->advanceAll($now);
        $p = [];
        foreach (PvoConfig::FELDER as $k => [, , $art]) {
            $v = $this->wert($k, $now);
            if ($v === null) {
                continue;
            }
            $p[$k] = $art === 'wert' ? self::zahl($v) : (string)(int)round($v);
        }
        if (!array_intersect_key($p, array_flip(['v1', 'v2', 'v3', 'v4']))) {
            return null;
        }
        $z1 = isset($p['v1']) && ($this->cfg['felder']['v1']['modus'] ?? '') === 'zaehler';
        $z3 = isset($p['v3']) && ($this->cfg['felder']['v3']['modus'] ?? '') === 'zaehler';
        if ($z1 || $z3) {
            $p['c1'] = $z1 && $z3 ? '1' : ($z1 ? '2' : '3');
        }
        return $p;
    }

    /** Uebersicht fuer die Weboberflaeche (Test-Reiter). */
    public function uebersicht(int $now): array
    {
        $out = [];
        foreach (PvoConfig::FELDER as $k => [$name, $einheit]) {
            $f = $this->cfg['felder'][$k];
            $roh = $f['topic'] !== '' ? $this->roh($f['topic'], $now) : null;
            $v = $this->wert($k, $now);
            $out[$k] = [
                'name' => $name, 'einheit' => $einheit, 'topic' => $f['topic'], 'modus' => $f['modus'] ?? '',
                'aktiv' => PvoConfig::feldAktiv($this->cfg, $k),
                'roh' => $roh[0] ?? null, 'alter' => $roh[1] ?? null, 'wert' => $v,
            ];
        }
        return $out;
    }

    private static function zahl(float $v): string
    {
        $s = number_format($v, 2, '.', '');
        return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
    }
}
