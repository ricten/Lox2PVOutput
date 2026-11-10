<?php
/**
 * Client fuer die PVOutput-API (https://pvoutput.org/help/api_specification.html).
 * API-Key und System-ID gehen nur als HTTP-Header (X-Pvoutput-Apikey / X-Pvoutput-SystemId) hinaus,
 * nie in die URL und nie in Meldungen.
 */
final class PvoClient
{
    public const string BASE = 'https://pvoutput.org/service/r2/';

    private string $base;

    public function __construct(private readonly string $apikey, private readonly string $systemid, ?string $base = null)
    {
        $b = $base ?? (getenv('PVO_DEV_URL') ?: self::BASE);       // PVO_DEV_URL nur fuer Tests
        $this->base = rtrim($b, '/') . '/';
    }

    /**
     * Anfrage senden.
     * @return array{code:int,body:string,error:string}  code 0 = keine Antwort (Netz, DNS, TLS, Zeitueberschreitung)
     */
    public function request(string $endpoint, array $params, bool $post): array
    {
        $query = http_build_query($params);
        $url = $this->base . $endpoint . (!$post && $query !== '' ? '?' . $query : '');
        $header = "X-Pvoutput-Apikey: {$this->apikey}\r\nX-Pvoutput-SystemId: {$this->systemid}\r\nAccept: text/plain\r\n";
        $http = ['method' => $post ? 'POST' : 'GET', 'timeout' => 15, 'ignore_errors' => true, 'header' => $header];
        if ($post) {
            $http['header'] .= "Content-Type: application/x-www-form-urlencoded\r\n";
            $http['content'] = $query;
        }
        $http_response_header = null;
        $body = @file_get_contents($url, false, stream_context_create(['http' => $http]));
        $code = 0;
        if (function_exists('http_get_last_response_headers')) {      // PHP >= 8.4 (Ersatz fuer die Variable)
            $http_response_header = http_get_last_response_headers();
        }
        foreach ((array)$http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string)$h, $m)) {
                $code = (int)$m[1];     // bei Weiterleitungen zaehlt die letzte Statuszeile
            }
        }
        if ($body === false || $code === 0) {
            $e = error_get_last();
            return ['code' => 0, 'body' => '', 'error' => self::clean((string)($e['message'] ?? 'keine Antwort')) ?: 'keine Antwort'];
        }
        return ['code' => $code, 'body' => self::clean($body), 'error' => ''];
    }

    /** Status senden (addstatus.jsp). @return array{ok:bool,retry:bool,msg:string} retry: erneuter Versuch sinnvoll (Netz/Serverfehler) */
    public function addStatus(array $params): array
    {
        $r = $this->request('addstatus.jsp', $params, true);
        return $this->result($r);
    }

    /** Zugang pruefen, ohne Daten zu schreiben (getstatus.jsp). */
    public function check(): array
    {
        $r = $this->request('getstatus.jsp', [], false);
        $res = $this->result($r);
        if (!$res['ok'] && $r['code'] === 400 && stripos($r['body'], 'no status') !== false) {
            return ['ok' => true, 'retry' => false, 'msg' => 'Zugang gültig, es sind noch keine Daten vorhanden'];
        }
        return $res;
    }

    /** @return array{ok:bool,retry:bool,msg:string} */
    private function result(array $r): array
    {
        $code = $r['code'];
        if ($code === 0) {
            return ['ok' => false, 'retry' => true, 'msg' => 'PVOutput nicht erreichbar: ' . $r['error']];
        }
        if ($code === 200) {
            return ['ok' => true, 'retry' => false, 'msg' => $r['body']];
        }
        if ($code === 401) {
            return ['ok' => false, 'retry' => false, 'msg' => 'API-Key oder System-ID ungültig (401)' . ($r['body'] !== '' ? ': ' . $r['body'] : '')];
        }
        return ['ok' => false, 'retry' => $code >= 500, 'msg' => "PVOutput antwortet $code" . ($r['body'] !== '' ? ': ' . $r['body'] : '')];
    }

    /** Antworttext fuer Anzeige/Log: ohne Tags und Umbrueche, begrenzt. */
    private static function clean(string $s): string
    {
        $s = trim((string)preg_replace('/\s+/', ' ', strip_tags($s)));
        return function_exists('mb_substr') ? mb_substr($s, 0, 200) : substr($s, 0, 200);
    }
}
