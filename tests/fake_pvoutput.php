<?php
/**
 * Fake-PVOutput fuer Tests: php tests/fake_pvoutput.php <port> <verzeichnis>
 * Schreibt jede Anfrage nach <verzeichnis>/pvo.log ("<METHODE>\t<Pfad>\t<apikey>\t<systemid>\t<Body>").
 * Antwortverhalten steuert die Datei <verzeichnis>/pvo_mode.txt: ok (Standard) | 401 | 403 | 500 | nostatus
 */
$port = (int)($argv[1] ?? 18900);
$dir = $argv[2] ?? sys_get_temp_dir();
$srv = stream_socket_server("tcp://127.0.0.1:$port", $e, $es);
if (!$srv) {
    fwrite(STDERR, "listen: $es\n");
    exit(1);
}
echo "listening $port\n";
while (true) {
    $c = @stream_socket_accept($srv, 1);
    if (!$c) {
        continue;
    }
    stream_set_timeout($c, 2);
    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $d = fread($c, 1);
        if ($d === '' || $d === false) {
            break;
        }
        $head .= $d;
    }
    $headers = [];
    foreach (explode("\r\n", $head) as $i => $line) {
        if ($i > 0 && str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }
    $body = '';
    $len = (int)($headers['content-length'] ?? 0);
    while (strlen($body) < $len) {
        $d = fread($c, $len - strlen($body));
        if ($d === '' || $d === false) {
            break;
        }
        $body .= $d;
    }
    [$method, $path] = explode(' ', $head, 3) + [1 => '', 2 => ''];
    file_put_contents("$dir/pvo.log", implode("\t", [$method, $path, $headers['x-pvoutput-apikey'] ?? '', $headers['x-pvoutput-systemid'] ?? '', $body]) . "\n", FILE_APPEND);
    $mode = trim((string)@file_get_contents("$dir/pvo_mode.txt")) ?: 'ok';
    $isStatus = str_contains($path, 'getstatus');
    if ($mode === '401') {
        [$code, $text, $out] = [401, 'Unauthorized', 'Unauthorized 401: Invalid API key'];
    } elseif ($mode === '403') {
        [$code, $text, $out] = [403, 'Forbidden', 'Forbidden 403: Exceeded number of requests per hour'];
    } elseif ($mode === '500') {
        [$code, $text, $out] = [500, 'Internal Server Error', '<html><body>Server <b>error</b></body></html>'];
    } elseif ($mode === 'nostatus' && $isStatus) {
        [$code, $text, $out] = [400, 'Bad Request', 'Bad request 400: No status found'];
    } else {
        [$code, $text, $out] = [200, 'OK', $isStatus ? '20260101,12:00,100,50,,,' : 'OK 200: Added Status'];
    }
    fwrite($c, "HTTP/1.1 $code $text\r\nContent-Type: text/plain\r\nContent-Length: " . strlen($out) . "\r\nConnection: close\r\n\r\n$out");
    fclose($c);
}
