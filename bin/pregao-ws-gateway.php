#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Gateway WebSocket do Pregão.
 *
 * Escuta 127.0.0.1:8091, autentica via ticket Redis (?ticket=),
 * consome a lista Redis pregao:fanout e faz broadcast.
 *
 * Nginx (sugerido):
 *   location /ws/pregao {
 *     proxy_pass http://127.0.0.1:8091;
 *     proxy_http_version 1.1;
 *     proxy_set_header Upgrade $http_upgrade;
 *     proxy_set_header Connection "upgrade";
 *     proxy_set_header Host $host;
 *     proxy_read_timeout 3600s;
 *   }
 *
 * Se este processo não estiver rodando, o frontend cai no SSE /api/pregao/stream.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

use App\Services\Pregao\PregaoStreamService;

$host = getenv('PREGAO_WS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('PREGAO_WS_PORT') ?: 8091);

$server = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "Falha ao bind {$host}:{$port} — {$errstr} ({$errno})\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDOUT, "[pregao-ws] listening on {$host}:{$port}\n");

/** @var array<int, array{sock: resource, account_id: int}> $clients */
$clients = [];
$streamService = new PregaoStreamService();

$redisHost = $_ENV['REDIS_HOST'] ?? getenv('REDIS_HOST') ?: '127.0.0.1';
$redisPort = (int) ($_ENV['REDIS_PORT'] ?? getenv('REDIS_PORT') ?: 6379);
$pass = $_ENV['REDIS_PASSWORD'] ?? getenv('REDIS_PASSWORD') ?: '';
$db = (int) ($_ENV['REDIS_DB'] ?? getenv('REDIS_DB') ?: 0);

$fan = new Redis();
$fan->connect((string) $redisHost, $redisPort, 1.5);
if (!empty($pass) && $pass !== 'null') {
    $fan->auth($pass);
}
$fan->select($db);

/**
 * @param resource $sock
 */
function pregao_ws_handshake($sock, PregaoStreamService $tickets): ?int
{
    $data = '';
    $deadline = microtime(true) + 5.0;
    while (microtime(true) < $deadline && strpos($data, "\r\n\r\n") === false) {
        $chunk = @fread($sock, 2048);
        if ($chunk === false || $chunk === '') {
            usleep(10000);
            continue;
        }
        $data .= $chunk;
    }
    if (!preg_match('#^GET\s+(\S+)#', $data, $m)) {
        return null;
    }
    $path = $m[1];
    $query = parse_url($path, PHP_URL_QUERY) ?: '';
    parse_str($query, $qs);
    $ticket = (string) ($qs['ticket'] ?? '');
    if ($ticket === '') {
        return null;
    }
    $auth = $tickets->consumeTicket($ticket);
    if ($auth === null) {
        return null;
    }

    if (!preg_match('/Sec-WebSocket-Key:\s*(\S+)/i', $data, $km)) {
        return null;
    }
    $key = trim($km[1]);
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $response = "HTTP/1.1 101 Switching Protocols\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";
    fwrite($sock, $response);
    return (int) $auth['account_id'];
}

/**
 * @param resource $sock
 */
function pregao_ws_send($sock, string $payload): void
{
    $len = strlen($payload);
    if ($len < 126) {
        $header = pack('CC', 0x81, $len);
    } elseif ($len < 65536) {
        $header = pack('CCn', 0x81, 126, $len);
    } else {
        $header = pack('CCNN', 0x81, 127, 0, $len);
    }
    @fwrite($sock, $header . $payload);
}

/**
 * @param array<int, array{sock: resource, account_id: int}> $clients
 */
function pregao_ws_broadcast(array &$clients, string $json): void
{
    $event = json_decode($json, true);
    $eventAccount = is_array($event) && isset($event['account_id']) ? (int) $event['account_id'] : null;

    foreach ($clients as $id => $client) {
        if ($eventAccount !== null && $client['account_id'] !== $eventAccount) {
            continue;
        }
        try {
            pregao_ws_send($client['sock'], $json);
        } catch (Throwable $e) {
            @fclose($client['sock']);
            unset($clients[$id]);
        }
    }
}

while (true) {
    $read = [$server];
    foreach ($clients as $c) {
        $read[] = $c['sock'];
    }
    $write = null;
    $except = null;
    @stream_select($read, $write, $except, 0, 200000);

    if (in_array($server, $read, true)) {
        $conn = @stream_socket_accept($server, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $accountId = pregao_ws_handshake($conn, $streamService);
            if ($accountId === null) {
                @fwrite($conn, "HTTP/1.1 401 Unauthorized\r\nConnection: close\r\n\r\n");
                @fclose($conn);
            } else {
                $clients[(int) $conn] = ['sock' => $conn, 'account_id' => $accountId];
                pregao_ws_send($conn, json_encode([
                    'v' => 1,
                    'type' => 'connected',
                    'ts' => date('c'),
                    'payload' => ['transport' => 'ws', 'account_id' => $accountId],
                    'account_id' => $accountId,
                ], JSON_UNESCAPED_UNICODE));
                fwrite(STDOUT, "[pregao-ws] client account={$accountId}\n");
            }
        }
    }

    foreach ($clients as $id => $client) {
        if (!in_array($client['sock'], $read, true)) {
            continue;
        }
        $chunk = @fread($client['sock'], 1024);
        if ($chunk === '' || $chunk === false) {
            @fclose($client['sock']);
            unset($clients[$id]);
            continue;
        }
        $opcode = ord($chunk[0]) & 0x0f;
        if ($opcode === 0x8) {
            @fclose($client['sock']);
            unset($clients[$id]);
        }
    }

    // BRPOP com timeout curto para não busy-loop
    $item = $fan->brPop(['pregao:fanout'], 1);
    if (is_array($item) && isset($item[1]) && is_string($item[1])) {
        pregao_ws_broadcast($clients, $item[1]);
        // Drain burst
        while (true) {
            $more = $fan->lPop('pregao:fanout');
            if (!is_string($more) || $more === '') {
                break;
            }
            pregao_ws_broadcast($clients, $more);
        }
    }
}
