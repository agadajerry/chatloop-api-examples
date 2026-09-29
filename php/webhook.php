<?php
// Single-host teaching example. Keep this file beside send-message.php.
declare(strict_types=1);
$inbox = getenv('CHATLOOP_INBOX') ?: __DIR__ . '/chatloop-inbox';
$channel = getenv('CHATLOOP_CHANNEL_ID');
$token = getenv('CHATLOOP_WEBHOOK_TOKEN') ?: '';
if (!$channel || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
    throw new RuntimeException('Set channel ID and a random 64-hex webhook token.');
}
if (!is_dir($inbox) && !mkdir($inbox, 0700, true)) throw new RuntimeException('Cannot create inbox.');

if (PHP_SAPI === 'cli' && in_array('--worker', $argv, true)) {
    if (!getenv('CHATLOOP_API_KEY')) throw new RuntimeException('Set the API key for CHATLOOP_CHANNEL_ID.');
    foreach (glob($inbox . '/*.json') as $file) {
        $claim = @fopen($file . '.claimed', 'x');
        if ($claim === false) {
            if (file_exists($file . '.claimed')) continue;
            throw new RuntimeException('Cannot claim inbox item.');
        }
        fclose($claim); // Never remove automatically, even if sending fails.
        $message = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $outcome = 'ignored';
        $chat = $message['chat_id'] ?? '';
        $text = $message['body']['text'] ?? null;
        if (($message['from_me'] ?? null) === false && ($message['type'] ?? '') === 'text' &&
            is_string($chat) && preg_match('/^([1-9][0-9]{6,14})@s\.whatsapp\.net$/D', $chat, $matches) &&
            is_string($text) && strtoupper(trim($text)) === 'HELP') {
            // Array command bypasses the shell; no untrusted text is executed.
            $env = getenv();
            $env['CHATLOOP_TO'] = $matches[1];
            $env['CHATLOOP_TEXT'] = 'Thanks for your reply. A support agent can help you here.';
            $process = proc_open([PHP_BINARY, __DIR__ . '/send-message.php'], [
                0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w'],
            ], $pipes, null, $env);
            $outcome = is_resource($process) && proc_close($process) === 0 ? 'accepted' : 'needs-review';
        }
        if (file_put_contents($file . '.result', $outcome) === false) throw new RuntimeException('Cannot save outcome.');
    }
    exit;
}

function respond(int $status): never { http_response_code($status); exit; }
if (($_SERVER['REQUEST_URI'] ?? '') !== '/webhooks/chatloop/' . $token) respond(404);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') respond(405);
if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) respond(415);
$raw = file_get_contents('php://input', false, null, 0, 1048577);
if ($raw === false) respond(400);
if (strlen($raw) > 1048576) respond(413);
try { $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
catch (JsonException $error) { respond(400); }
if (!is_array($payload)) respond(400);
if (($payload['channel_id'] ?? '') !== $channel) respond(403);
if (($payload['event']['type'] ?? '') !== 'messages' || ($payload['event']['event'] ?? '') !== 'post') respond(204);
if (!isset($payload['messages']) || !is_array($payload['messages']) || !array_is_list($payload['messages'])) respond(400);
foreach ($payload['messages'] as $message) {
    if (!is_array($message) || !is_string($message['id'] ?? null) || $message['id'] === '') respond(400);
}
foreach ($payload['messages'] as $message) {
    $destination = $inbox . '/' . hash('sha256', $channel . ':' . $message['id']) . '.json';
    $temporary = tempnam($inbox, '.incoming-');
    if ($temporary === false) respond(503);
    $written = file_put_contents($temporary, json_encode($message, JSON_THROW_ON_ERROR));
    $saved = $written !== false && (@link($temporary, $destination) || file_exists($destination));
    unlink($temporary);
    if (!$saved) respond(503);
}
respond(204); // Acknowledgement only; the separate worker sends replies.
