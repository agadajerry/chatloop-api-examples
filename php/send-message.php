<?php
// PHP 8.1+ with ext-curl. Run from the CLI or a trusted backend.
declare(strict_types=1);
$key = getenv('CHATLOOP_API_KEY');
$to = getenv('CHATLOOP_TO');
$text = getenv('CHATLOOP_TEXT') ?: 'Thanks for contacting us. We have received your enquiry.';
if (!$key || !$to || !preg_match('/^[1-9][0-9]{6,14}$/D', $to)) {
    fwrite(STDERR, "Set CHATLOOP_API_KEY and CHATLOOP_TO (country code + digits, without +).\n");
    exit(1);
}
if (!extension_loaded('curl')) {
    fwrite(STDERR, "Enable the PHP cURL extension before running this example.\n");
    exit(1);
}
try {
    $payload = json_encode(['to' => $to, 'text' => $text], JSON_THROW_ON_ERROR);
    $curl = curl_init('https://api.chatloophq.com/api/send/text');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['X-API-Key: ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($body === false) {
        throw new RuntimeException('Network error');
    }
    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, "HTTP {$status}: {$body}\n");
        exit(1);
    }
    echo $status === 202 ? "Queued; do not resend.\n" : "Request succeeded; not a delivery receipt.\n";
    echo $body . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, "Request failed. If a request was sent, check message history before retrying.\n");
    exit(1);
}
