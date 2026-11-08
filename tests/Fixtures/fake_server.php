<?php

declare(strict_types=1);

/*
 * Minimal HTTP server for the tests: answers each request with the next scripted
 * response from a JSON file and appends every request it gets to a log file.
 * A response of "drop" closes the connection without answering (a network error).
 *
 * Usage: php fake_server.php <responses.json> <requests.log> <port-file>
 */

[, $responsesFile, $logFile, $portFile] = $argv;

$responses = json_decode((string) file_get_contents($responsesFile), true);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "fake server: {$errstr}\n");
    exit(1);
}
file_put_contents($portFile, (string) parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT));

while ($conn = @stream_socket_accept($server, -1)) {
    $requestLine = fgets($conn);
    if ($requestLine === false) {
        fclose($conn);
        continue;
    }
    [$method, $path] = explode(' ', trim($requestLine));

    $headers = [];
    while (($line = fgets($conn)) !== false && $line !== "\r\n") {
        [$name, $value] = explode(':', $line, 2);
        $headers[strtolower(trim($name))] = trim($value);
    }

    $length = (int) ($headers['content-length'] ?? 0);
    $body = '';
    while (strlen($body) < $length && !feof($conn)) {
        $body .= fread($conn, $length - strlen($body));
    }

    $entry = ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body];
    file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

    $response = array_shift($responses) ?? ['status' => 500, 'body' => ['error' => 'no response scripted']];
    if ($response === 'drop') {
        fclose($conn);
        continue;
    }

    $payload = array_key_exists('body', $response) ? json_encode($response['body']) : '';
    $extra = '';
    foreach ($response['headers'] ?? [] as $name => $value) {
        $extra .= "{$name}: {$value}\r\n";
    }
    fwrite($conn, "HTTP/1.1 {$response['status']} X\r\nContent-Type: application/json\r\n"
        . 'Content-Length: ' . strlen($payload) . "\r\nConnection: close\r\n{$extra}\r\n{$payload}");
    fclose($conn);
}
