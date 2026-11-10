<?php
/**
 * Konfiguration des Plugins (pvoutput.json, Rechte 0600).
 *
 * Felder nach der PVOutput-API (addstatus.jsp):
 *   v1 Erzeugung Energie (Wh, Tageswert)   v2 Erzeugung Leistung (W)
 *   v3 Verbrauch Energie (Wh, Tageswert)   v4 Verbrauch Leistung (W)
 *   v5 Temperatur (Grad C)                 v6 Spannung (V)
 *   v7..v12 erweiterte Werte (nur mit PVOutput-Spende nutzbar)
 *
 * Woher ein Feld seinen Wert bekommt, waehlt man in einer Liste:
 *   - eine Quelle mit einem ihrer Werte (Fronius2Lox, Senec2Lox) - das Plugin setzt das Topic selbst zusammen,
 *   - ein eigenes MQTT-Topic (beliebige Quelle),
 *   - bei v1/v3 zusaetzlich: aus der Leistung (v2/v4) berechnen oder nicht senden.
 * Dazu gibt es einen Faktor (z. B. 1000 bei kW -> W).
 *
 * Gespeichert wird je Feld: quelle (aus | topic | fronius | senec2lox), wert (Schluessel im Katalog), eigenes (eigenes Topic),
 * faktor und bei v1/v3 modus. Zusaetzlich liefert load() das fertige Topic im Schluessel "topic" (wird nicht geaendert, nur berechnet).
 */
final class PvoConfig
{
    /** Schluessel => [Bezeichnung, Einheit, Art]. Art: energie | leistung | wert */
    public const array FELDER = [
        'v1' => ['Erzeugung Energie', 'Wh', 'energie'],
        'v2' => ['Erzeugung Leistung', 'W', 'leistung'],
        'v3' => ['Verbrauch Energie', 'Wh', 'energie'],
        'v4' => ['Verbrauch Leistung', 'W', 'leistung'],
        'v5' => ['Temperatur', '°C', 'wert'],
        'v6' => ['Spannung', 'V', 'wert'],
        'v7' => ['Erweitert 1', '', 'wert'],
        'v8' => ['Erweitert 2', '', 'wert'],
        'v9' => ['Erweitert 3', '', 'wert'],
        'v10' => ['Erweitert 4', '', 'wert'],
        'v11' => ['Erweitert 5', '', 'wert'],
        'v12' => ['Erweitert 6', '', 'wert'],
    ];

    /** Betriebsarten der Energiefelder v1/v3. */
    public const array MODI = [
        'aus' => 'nicht senden',
        'integral' => 'aus der Leistung berechnen (Tagessumme)',
        'topic' => 'Tageswert aus Topic (Wh seit Mitternacht)',
        'zaehler' => 'Gesamtzähler aus Topic (Wh, ewig steigend)',
    ];

    public const array INTERVALLE = [5, 10, 15];

    /** Quellen: Schluessel => Bezeichnung. Die Topics der Quellen stehen im Katalog unten. */
    public const array QUELLEN = ['fronius' => 'Fronius2Lox', 'senec2lox' => 'Senec2Lox', 'loxone' => 'Loxone (virtueller Ausgang)'];

    /** Eingangs-Topics fuer Werte aus Loxone: loxone/pvoutput/<Name>. Der Miniserver schreibt sie per UDP-Befehl ins MQTT-Gateway. */
    public const string LOXONE_PRAEFIX = 'loxone/pvoutput/';

    /**
     * Welche Katalog-Arten ein Feld annimmt. leistung = W, tag = Wh seit Mitternacht, zaehler = Wh gesamt (steigend),
     * temp = Grad C, spannung = V, sonst = alles Weitere (nur fuer die erweiterten Felder v7-v12 sinnvoll).
     */
    public const array ARTEN = [
        'v1' => ['tag', 'zaehler'], 'v3' => ['tag', 'zaehler'],
        'v2' => ['leistung'], 'v4' => ['leistung'],
        'v5' => ['temp'], 'v6' => ['spannung'],
    ];

    /**
     * Katalog der Werte, die andere LoxBerry-Plugins veroeffentlichen. Schluessel "<quelle>:<name>" =>
     * [Topic relativ zur Quelle, Bezeichnung, Art]. Fronius2Lox: fronius/<Geraetename>/<Pfad>; Senec2Lox: <Praefix>/live/<Pfad>.
     * Nummern im Pfad (wr/1, zaehler/0 ...) gelten fuer das erste Geraet; andere Geraete ueber "eigenes Topic".
     */
    public const array KATALOG = [
        // Fronius2Lox
        'fronius:pv_w' => ['pv_w', 'PV-Leistung (W)', 'leistung'],
        'fronius:wr_leistung' => ['wr/{wr}/leistung_ac_w', 'Wechselrichter: AC-Leistung (W)', 'leistung'],
        'fronius:haus_w' => ['haus_w', 'Hausverbrauch (W), nur mit Smart Meter', 'leistung'],
        'fronius:netz_bezug_w' => ['netz_bezug_w', 'Netzbezug (W)', 'leistung'],
        'fronius:netz_einspeisung_w' => ['netz_einspeisung_w', 'Netzeinspeisung (W)', 'leistung'],
        'fronius:batterie_laden_w' => ['batterie_laden_w', 'Batterie laden (W)', 'leistung'],
        'fronius:batterie_entladen_w' => ['batterie_entladen_w', 'Batterie entladen (W)', 'leistung'],
        'fronius:ohmpilot_w' => ['ohmpilot/{ohmpilot}/leistung_w', 'OhmPilot: Leistung (W)', 'leistung'],
        'fronius:energie_tag_wh' => ['energie_tag_wh', 'Erzeugung heute (Wh)', 'tag'],
        'fronius:wr_energie_tag_wh' => ['wr/{wr}/energie_tag_wh', 'Wechselrichter: Erzeugung heute (Wh)', 'tag'],
        'fronius:energie_gesamt_wh' => ['energie_gesamt_wh', 'Erzeugung gesamt (Wh)', 'zaehler'],
        'fronius:wr_energie_gesamt_wh' => ['wr/{wr}/energie_gesamt_wh', 'Wechselrichter: Erzeugung gesamt (Wh)', 'zaehler'],
        'fronius:temp_umgebung' => ['wr/{wr}/temperatur_umgebung_c', 'Wechselrichter: Umgebungstemperatur (°C), nur wenige Modelle', 'temp'],
        'fronius:sensor_temp1' => ['sensor/{sensor}/kanal_0', 'Sensorkarte: Kanal 0 (laut Doku-Beispiel Temperatur 1, °C)', 'temp'],
        'fronius:sensor_temp2' => ['sensor/{sensor}/kanal_1', 'Sensorkarte: Kanal 1 (laut Doku-Beispiel Temperatur 2, °C)', 'temp'],
        'fronius:ohmpilot_temp' => ['ohmpilot/{ohmpilot}/temperatur_c', 'OhmPilot: Speichertemperatur (°C)', 'temp'],
        'fronius:wr_spannung_ac' => ['wr/{wr}/spannung_ac_v', 'Wechselrichter: AC-Spannung (V)', 'spannung'],
        'fronius:wr_spannung_dc' => ['wr/{wr}/spannung_dc_v', 'Wechselrichter: DC-Spannung (V)', 'spannung'],
        'fronius:zaehler_spannung_l1' => ['zaehler/{zaehler}/spannung_l1_v', 'Smart Meter: Spannung L1 (V)', 'spannung'],
        'fronius:netz_w' => ['netz_w', 'Netzleistung (W, + Bezug / − Einspeisung)', 'sonst'],
        'fronius:batterie_w' => ['batterie_w', 'Batterieleistung (W, + Laden / − Entladen)', 'sonst'],
        'fronius:batterie_soc' => ['batterie_soc', 'Batterie-Ladestand (%)', 'sonst'],
        'fronius:autarkie' => ['autarkie', 'Autarkiegrad (%)', 'sonst'],
        'fronius:eigenverbrauch' => ['eigenverbrauch', 'Eigenverbrauchsquote (%)', 'sonst'],
        'fronius:wr_strom_ac' => ['wr/{wr}/strom_ac_a', 'Wechselrichter: AC-Strom (A)', 'sonst'],
        'fronius:wr_frequenz' => ['wr/{wr}/frequenz_hz', 'Wechselrichter: Netzfrequenz (Hz)', 'sonst'],
        'fronius:wr_strom_dc' => ['wr/{wr}/strom_dc_a', 'Wechselrichter: DC-Strom (A)', 'sonst'],
        'fronius:zaehler_leistung' => ['zaehler/{zaehler}/leistung_w', 'Smart Meter: Leistung (W)', 'sonst'],
        'fronius:sensor_einstrahlung' => ['sensor/{sensor}/kanal_2', 'Sensorkarte: Kanal 2 (laut Doku-Beispiel Einstrahlung, W/m²)', 'sonst'],
        // Senec2Lox
        'senec2lox:pv_w' => ['pv_w', 'PV-Leistung (W)', 'leistung'],
        'senec2lox:haus_w' => ['haus_w', 'Hausverbrauch (W)', 'leistung'],
        'senec2lox:netz_bezug_w' => ['netz_bezug_w', 'Netzbezug (W)', 'leistung'],
        'senec2lox:netz_einspeisung_w' => ['netz_einspeisung_w', 'Netzeinspeisung (W)', 'leistung'],
        'senec2lox:batterie_laden_w' => ['batterie_laden_w', 'Batterie laden (W)', 'leistung'],
        'senec2lox:batterie_entladen_w' => ['batterie_entladen_w', 'Batterie entladen (W)', 'leistung'],
        'senec2lox:wallbox_w' => ['wallbox_w', 'Wallbox (W)', 'leistung'],
        'senec2lox:temp_batterie_max' => ['temp_batterie_max_c', 'Batterie: höchste Temperatur (°C)', 'temp'],
        'senec2lox:temp_batterie_min' => ['temp_batterie_min_c', 'Batterie: niedrigste Temperatur (°C)', 'temp'],
        'senec2lox:batterie_spannung' => ['batterie_spannung_v', 'Batteriespannung (V)', 'spannung'],
        'senec2lox:netz_w' => ['netz_w', 'Netzleistung (W, + Bezug / − Einspeisung)', 'sonst'],
        'senec2lox:batterie_w' => ['batterie_w', 'Batterieleistung (W, + Laden / − Entladen)', 'sonst'],
        'senec2lox:batterie_soc' => ['batterie_soc', 'Batterie-Ladestand (%)', 'sonst'],
        'senec2lox:batterie_strom' => ['batterie_strom_a', 'Batteriestrom (A)', 'sonst'],
        'senec2lox:netz_frequenz' => ['netz_frequenz_hz', 'Netzfrequenz (Hz)', 'sonst'],
        'senec2lox:phase_l1' => ['phase_l1_w', 'Leistung Phase L1 (W)', 'sonst'],
        'senec2lox:phase_l2' => ['phase_l2_w', 'Leistung Phase L2 (W)', 'sonst'],
        'senec2lox:phase_l3' => ['phase_l3_w', 'Leistung Phase L3 (W)', 'sonst'],
        // Loxone (virtuelle Ausgaenge): feste Eingangs-Topics je PVOutput-Feld, 4. Element = nur fuer diese Felder
        'loxone:v1_tag' => ['erzeugung_energie_tag_wh', 'Erzeugung Energie heute (Wh)', 'tag', ['v1']],
        'loxone:v1_gesamt' => ['erzeugung_energie_gesamt_wh', 'Erzeugung Energie gesamt (Wh)', 'zaehler', ['v1']],
        'loxone:v2' => ['erzeugung_leistung_w', 'Erzeugung Leistung (W)', 'leistung', ['v2']],
        'loxone:v3_tag' => ['verbrauch_energie_tag_wh', 'Verbrauch Energie heute (Wh)', 'tag', ['v3']],
        'loxone:v3_gesamt' => ['verbrauch_energie_gesamt_wh', 'Verbrauch Energie gesamt (Wh)', 'zaehler', ['v3']],
        'loxone:v4' => ['verbrauch_leistung_w', 'Verbrauch Leistung (W)', 'leistung', ['v4']],
        'loxone:v5' => ['temperatur_c', 'Temperatur (°C)', 'temp', ['v5']],
        'loxone:v6' => ['spannung_v', 'Spannung (V)', 'spannung', ['v6']],
        'loxone:v7' => ['erweitert_1', 'Erweitert 1', 'sonst', ['v7']],
        'loxone:v8' => ['erweitert_2', 'Erweitert 2', 'sonst', ['v8']],
        'loxone:v9' => ['erweitert_3', 'Erweitert 3', 'sonst', ['v9']],
        'loxone:v10' => ['erweitert_4', 'Erweitert 4', 'sonst', ['v10']],
        'loxone:v11' => ['erweitert_5', 'Erweitert 5', 'sonst', ['v11']],
        'loxone:v12' => ['erweitert_6', 'Erweitert 6', 'sonst', ['v12']],
    ];

    public static function defaults(): array
    {
        $felder = [];
        foreach (self::FELDER as $k => [, , $art]) {
            $felder[$k] = ['quelle' => 'aus', 'wert' => '', 'eigenes' => '', 'faktor' => 1.0] + ($art === 'energie' ? ['modus' => 'integral'] : []);
        }
        // Vorgabe passend zu Senec2Lox; jederzeit aenderbar
        $felder['v2'] = ['quelle' => 'senec2lox', 'wert' => 'senec2lox:pv_w'] + $felder['v2'];
        $felder['v4'] = ['quelle' => 'senec2lox', 'wert' => 'senec2lox:haus_w'] + $felder['v4'];
        return self::finalize([
            'aktiv' => false,
            'apikey' => '',
            'systemid' => '',
            'intervall' => 5,       // Minuten, wie in den PVOutput-Systemeinstellungen
            'max_alter' => 900,     // Sekunden: aelteren Werten wird nicht mehr vertraut
            'quellen' => ['fronius_name' => 'wr1', 'senec_praefix' => 'senec', 'fronius_wr' => '', 'fronius_zaehler' => '', 'fronius_ohmpilot' => '', 'fronius_sensor' => ''],
            'felder' => $felder,
            'mqtt' => ['eigener_broker' => false, 'host' => '', 'port' => 1883, 'benutzer' => '', 'passwort' => ''],
        ]);
    }

    public static function load(string $file): array
    {
        $def = self::defaults();
        $c = Util::jsonLoad($file);
        $out = array_merge($def, $c);
        $out['aktiv'] = (bool)$out['aktiv'];
        $out['apikey'] = (string)$out['apikey'];
        $out['systemid'] = (string)preg_replace('/\D/', '', (string)$out['systemid']);
        $out['intervall'] = in_array((int)$out['intervall'], self::INTERVALLE, true) ? (int)$out['intervall'] : 5;
        $out['max_alter'] = max(60, min(86400, (int)$out['max_alter']));
        $out['mqtt'] = array_merge($def['mqtt'], is_array($c['mqtt'] ?? null) ? $c['mqtt'] : []);
        $out['quellen'] = self::cleanQuellen(array_merge($def['quellen'], is_array($c['quellen'] ?? null) ? $c['quellen'] : []));
        $felder = [];
        foreach ($def['felder'] as $k => $d) {
            $f = is_array($c['felder'][$k] ?? null) ? $c['felder'][$k] : [];
            if (isset($f['topic']) && !isset($f['quelle'])) {             // alte Konfiguration: nur ein Topic
                $f = ['quelle' => $f['topic'] !== '' ? 'topic' : 'aus', 'eigenes' => $f['topic']] + $f;
            }
            $felder[$k] = self::cleanField(array_merge($d, $f), $d, $k);
        }
        $out['felder'] = $felder;
        return self::finalize($out);
    }

    /** Quellen-Einstellungen: Geraetename (a-z 0-9 _ -) und Topic-Praefix von Senec2Lox. */
    public static function cleanQuellen(array $q): array
    {
        $name = Util::name((string)($q['fronius_name'] ?? ''));
        $pre = self::cleanTopic((string)($q['senec_praefix'] ?? ''));
        $out = ['fronius_name' => $name !== '' ? $name : 'wr1', 'senec_praefix' => $pre !== '' ? $pre : 'senec'];
        foreach (array_keys(self::NUMMERN) as $typ) {        // Nummer des Geraets: leer = automatisch aus den Topics erkennen
            $nr = trim((string)($q['fronius_' . $typ] ?? ''));
            $out['fronius_' . $typ] = preg_match('/^\d{1,10}$/', $nr) ? (string)(int)$nr : '';
        }
        return $out;
    }

    /**
     * Geraete mit Nummer im Fronius2Lox-Topic: Art => [Bezeichnung, Standardnummer, Topic-Endung zum Erkennen].
     * Der Wechselrichter hat die Nummer, die Fronius ihm gibt (z. B. 15, nicht immer 1); das Plugin liest sie aus den Topics.
     */
    public const array NUMMERN = [
        'wr' => ['Wechselrichter', '1', 'leistung_ac_w'],
        'zaehler' => ['Smart Meter', '0', 'leistung_w'],
        'ohmpilot' => ['OhmPilot', '0', 'leistung_w'],
        'sensor' => ['Sensorkarte', '1', 'kanal_0'],
    ];

    /**
     * Nummer je Geraeteart: von Hand eingetragen, sonst aus den Topics erkannt, sonst Standard (1 bzw. 0).
     * $entdeckt: ['geraete' => [Name => [Art => [Nummer => letzte Sichtung (Unix-Zeit)]]], 'namen' => [Name => Sichtung]]. Bei mehreren erkannten Nummern zaehlen nur die, die zuletzt
     * (innerhalb einer Stunde vor der juengsten Sichtung) gesehen wurden; davon die kleinste. So verdraengt ein neuer
     * Wechselrichter (z. B. 15) die alte Nummer 1.
     * @return array<string,string>
     */
    public static function nummern(array $quellen, array $entdeckt): array
    {
        $out = [];
        foreach (self::NUMMERN as $typ => [, $standard]) {
            $hand = (string)($quellen['fronius_' . $typ] ?? '');
            if ($hand !== '') {
                $out[$typ] = $hand;
                continue;
            }
            $gesehen = is_array($entdeckt['geraete'][$quellen['fronius_name']][$typ] ?? null) ? array_filter($entdeckt['geraete'][$quellen['fronius_name']][$typ], 'is_numeric') : [];
            if ($gesehen) {
                $neu = max($gesehen);
                $nrs = array_keys(array_filter($gesehen, fn($t) => $t >= $neu - 3600));
                sort($nrs, SORT_NUMERIC);
                $out[$typ] = (string)$nrs[0];
            } else {
                $out[$typ] = $standard;
            }
        }
        return $out;
    }

    /** Konfiguration mit den erkannten Geraetenummern (setzt quellen.nr und berechnet die Topics neu). */
    public static function mitEntdeckten(array $cfg, array $entdeckt): array
    {
        $cfg['quellen']['nr'] = self::nummern($cfg['quellen'], $entdeckt);
        return self::finalize($cfg);
    }

    /** Topic-Filter (mit +), an denen der Dienst Namen und Geraetenummern von Fronius2Lox erkennt (immer, damit die Auswahllisten gefuellt sind). @return list<string> */
    public static function entdeckungsFilter(): array
    {
        $out = [];
        foreach (self::NUMMERN as $typ => [, , $ende]) {
            $out[] = 'fronius/+/' . $typ . '/+/' . $ende;
        }
        return $out;
    }

    /** Name, Art und Nummer aus einem Fronius2Lox-Topic (fronius/<Name>/wr/15/...), sonst null. @return array{0:string,1:string,2:string}|null */
    public static function geraetAusTopic(string $topic): ?array
    {
        $rest = explode('/', $topic);
        return (count($rest) >= 5 && $rest[0] === 'fronius' && $rest[1] !== '' && isset(self::NUMMERN[$rest[2]]) && preg_match('/^\d{1,10}$/', $rest[3]))
            ? [$rest[1], $rest[2], (string)(int)$rest[3]] : null;
    }

    /** Ein Feld bereinigen. */
    private static function cleanField(array $f, array $d, string $k): array
    {
        $quelle = (string)($f['quelle'] ?? 'aus');
        $wert = (string)($f['wert'] ?? '');
        if (!in_array($quelle, ['aus', 'topic'], true)) {
            if (!isset(self::QUELLEN[$quelle]) || !self::erlaubt($k, $wert) || !str_starts_with($wert, $quelle . ':')) {
                $quelle = 'aus';
                $wert = '';
            }
        } else {
            $wert = '';
        }
        $faktor = is_numeric($f['faktor'] ?? 1) ? (float)$f['faktor'] : 1.0;
        $out = ['quelle' => $quelle, 'wert' => $wert, 'eigenes' => self::cleanTopic((string)($f['eigenes'] ?? '')), 'faktor' => $faktor != 0.0 ? $faktor : 1.0];
        if (isset($d['modus'])) {
            $m = isset(self::MODI[$f['modus'] ?? '']) ? (string)$f['modus'] : (string)$d['modus'];
            if (isset(self::KATALOG[$wert])) {         // der Katalogwert bestimmt die Betriebsart
                $m = self::KATALOG[$wert][2] === 'tag' ? 'topic' : 'zaehler';
            } elseif ($quelle === 'aus') {
                $m = in_array($m, ['integral', 'aus'], true) ? $m : 'aus';
            }
            $out['modus'] = $m;
        }
        return $out;
    }

    /** Berechnet das fertige Topic je Feld (Schluessel "topic") aus Quelle, Wert und Quellen-Einstellungen. */
    public static function finalize(array $cfg): array
    {
        foreach ($cfg['felder'] as $k => $f) {
            $cfg['felder'][$k]['topic'] = self::topicOf($cfg['quellen'], $f);
        }
        return $cfg;
    }

    public static function topicOf(array $quellen, array $f): string
    {
        switch ($f['quelle'] ?? 'aus') {
            case 'topic':
                return (string)$f['eigenes'];
            case 'fronius':
                $e = self::KATALOG[$f['wert']] ?? null;
                if (!$e) {
                    return '';
                }
                $nr = $quellen['nr'] ?? self::nummern($quellen, []);
                return 'fronius/' . $quellen['fronius_name'] . '/' . str_replace(['{wr}', '{zaehler}', '{ohmpilot}', '{sensor}'],
                    [$nr['wr'], $nr['zaehler'], $nr['ohmpilot'], $nr['sensor']], $e[0]);
            case 'senec2lox':
                $e = self::KATALOG[$f['wert']] ?? null;
                return $e ? $quellen['senec_praefix'] . '/live/' . $e[0] : '';
            case 'loxone':
                $e = self::KATALOG[$f['wert']] ?? null;
                return $e ? self::LOXONE_PRAEFIX . $e[0] : '';
            default:
                return '';
        }
    }

    /** Darf der Katalogwert fuer dieses Feld gewaehlt werden? (v7-v12 nehmen alles.) */
    public static function erlaubt(string $feld, string $wert): bool
    {
        $e = self::KATALOG[$wert] ?? null;
        if ($e === null || (isset($e[3]) && !in_array($feld, $e[3], true))) {
            return false;           // Loxone-Eingaenge gehoeren zu genau einem Feld
        }
        return !isset(self::ARTEN[$feld]) || in_array($e[2], self::ARTEN[$feld], true);
    }

    /**
     * Auswahlliste fuer ein Feld: Quelle => [Schluessel => Bezeichnung]. Energiefelder bekommen vorneweg die Betriebsarten.
     * @return array<string,array<string,string>>
     */
    public static function auswahl(string $feld): array
    {
        $out = [];
        if ((self::FELDER[$feld][2] ?? '') === 'energie') {
            $out['Betriebsart'] = ['integral' => self::MODI['integral'], 'aus' => self::MODI['aus'],
                'eigen_tag' => 'Eigenes Topic: ' . self::MODI['topic'], 'eigen_zaehler' => 'Eigenes Topic: ' . self::MODI['zaehler']];
        } else {
            $out['Betriebsart'] = ['aus' => 'nicht senden', 'eigen' => 'Eigenes MQTT-Topic'];
        }
        foreach (self::QUELLEN as $q => $name) {
            foreach (self::KATALOG as $key => [, $label]) {
                if (str_starts_with($key, $q . ':') && self::erlaubt($feld, $key)) {
                    $out[$name][$key] = $label . (self::KATALOG[$key][2] === 'zaehler' ? ' – Gesamtzähler' : (self::KATALOG[$key][2] === 'tag' ? ' – Tageswert' : ''));
                }
            }
        }
        return array_filter($out);
    }

    /** Aktuelle Auswahl eines Feldes als Schluessel der Auswahlliste. */
    public static function auswahlWert(array $f): string
    {
        $energie = isset($f['modus']);
        if ($energie && in_array($f['modus'], ['integral', 'aus'], true)) {
            return $f['modus'];
        }
        return match ($f['quelle']) {
            'topic' => $energie ? ($f['modus'] === 'zaehler' ? 'eigen_zaehler' : 'eigen_tag') : 'eigen',
            'aus' => 'aus',
            default => (string)$f['wert'],
        };
    }

    /**
     * Die festen Loxone-Eingangs-Topics je PVOutput-Feld (fuer die Anleitung in der Oberflaeche).
     * @return list<array{feld:string,topic:string,bezeichnung:string,art:string}>
     */
    public static function loxoneEingaenge(): array
    {
        $out = [];
        foreach (self::KATALOG as $key => $e) {
            if (str_starts_with($key, 'loxone:')) {
                $out[] = ['feld' => $e[3][0], 'topic' => self::LOXONE_PRAEFIX . $e[0], 'bezeichnung' => $e[1], 'art' => $e[2]];
            }
        }
        return $out;
    }

    /** Topic: genau ein Topic, keine Platzhalter (+ #), keine Steuerzeichen. */
    public static function cleanTopic(string $t): string
    {
        $t = trim($t);
        if ($t === '' || preg_match('/[+#\x00-\x1f\x7f]/', $t) || strlen($t) > 200) {
            return '';
        }
        return trim($t, '/');
    }

    /** Eingaben der Weboberflaeche pruefen und mit der bisherigen Konfiguration zusammenfuehren. */
    public static function fromPost(array $old, array $post): array
    {
        $new = $old;
        // Jeder Reiter der Oberflaeche sendet nur seine Abschnitte; fehlende bleiben unveraendert.
        if (isset($post['systemid']) || isset($post['felder'])) {
            $new['aktiv'] = !empty($post['aktiv']);
            $key = trim((string)($post['apikey'] ?? ''));
            $new['apikey'] = $key !== '' ? (string)preg_replace('/[^A-Za-z0-9]/', '', $key) : (string)$old['apikey'];
            $new['systemid'] = (string)preg_replace('/\D/', '', (string)($post['systemid'] ?? ''));
            $iv = (int)($post['intervall'] ?? 5);
            $new['intervall'] = in_array($iv, self::INTERVALLE, true) ? $iv : 5;
            $new['max_alter'] = max(60, min(86400, (int)($post['max_alter'] ?? 900)));
            if (is_array($post['quellen'] ?? null)) {
                $new['quellen'] = self::cleanQuellen(array_merge($old['quellen'], $post['quellen']));
            }
            $def = self::defaults()['felder'];
            foreach ($def as $k => $d) {
                $p = is_array($post['felder'][$k] ?? null) ? $post['felder'][$k] : [];
                $faktor = str_replace(',', '.', trim((string)($p['faktor'] ?? '1')));
                $f = ['quelle' => 'aus', 'wert' => '', 'eigenes' => (string)($p['eigenes'] ?? $p['topic'] ?? ''),
                    'faktor' => is_numeric($faktor) ? (float)$faktor : 1.0] + (isset($d['modus']) ? ['modus' => (string)($p['modus'] ?? $d['modus'])] : []);
                if (isset($p['auswahl'])) {                      // Auswahlliste der Oberflaeche
                    $a = (string)$p['auswahl'];
                    if ($a === 'integral' || ($a === 'aus' && isset($d['modus']))) {
                        $f['modus'] = $a;
                    } elseif ($a === 'eigen' || $a === 'eigen_tag' || $a === 'eigen_zaehler') {
                        $f['quelle'] = 'topic';
                        if ($a !== 'eigen') {
                            $f['modus'] = $a === 'eigen_zaehler' ? 'zaehler' : 'topic';
                        }
                    } elseif (strpos($a, ':') !== false) {
                        $f['quelle'] = explode(':', $a, 2)[0];
                        $f['wert'] = $a;
                    }
                } elseif ($f['eigenes'] !== '') {                // aeltere Eingabe: nur Topic (und Betriebsart)
                    $f['quelle'] = 'topic';
                }
                $new['felder'][$k] = self::cleanField($f, $d, $k);
            }
        }
        if (is_array($post['mqtt'] ?? null)) {
            $m = $post['mqtt'];
            $mpw = (string)($m['passwort'] ?? '');
            $new['mqtt'] = [
                'eigener_broker' => !empty($m['eigener_broker']),
                'host' => (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', trim((string)($m['host'] ?? ''))),
                'port' => max(1, min(65535, (int)($m['port'] ?? 1883))),
                'benutzer' => trim((string)($m['benutzer'] ?? '')),
                'passwort' => $mpw !== '' ? $mpw : (string)$old['mqtt']['passwort'],
            ];
        }
        return self::finalize($new);
    }

    /** Alle Topics, die der Dienst abonnieren muss (je Feld mit Topic; Energie aus Leistung braucht kein eigenes). */
    public static function topics(array $cfg): array
    {
        $out = [];
        foreach ($cfg['felder'] as $k => $f) {
            if ($f['topic'] !== '' && self::feldAktiv($cfg, $k) && ($f['modus'] ?? '') !== 'integral') {
                $out[$k] = $f['topic'];
            }
        }
        return $out;
    }

    /** Wird das Feld gesendet? Energie: nach Betriebsart; sonst: wenn ein Topic gewaehlt ist. */
    public static function feldAktiv(array $cfg, string $k): bool
    {
        $f = $cfg['felder'][$k];
        if (!isset($f['modus'])) {
            return $f['topic'] !== '';
        }
        return match ($f['modus']) {
            'integral' => $cfg['felder'][$k === 'v1' ? 'v2' : 'v4']['topic'] !== '',
            'topic', 'zaehler' => $f['topic'] !== '',
            default => false,
        };
    }

    /** Sind API-Key und System-ID vorhanden? */
    public static function zugangOk(array $cfg): bool
    {
        return $cfg['apikey'] !== '' && $cfg['systemid'] !== '';
    }

    /**
     * Eigener MQTT-Broker aus der Konfiguration (statt dem LoxBerry-Broker).
     * @return array{0:string,1:int,2:string,3:string}|null  [host, port, user, pass]; null = LoxBerry-Broker verwenden
     */
    public static function ownBroker(array $cfg): ?array
    {
        $m = $cfg['mqtt'];
        if (empty($m['eigener_broker']) || trim((string)$m['host']) === '') {
            return null;
        }
        return [trim((string)$m['host']), (int)$m['port'] > 0 ? (int)$m['port'] : 1883, (string)$m['benutzer'], (string)$m['passwort']];
    }
}
