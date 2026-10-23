<?php

/*
 * Development-only stand-in for the Next.js endpoint that receives revalidation requests.
 * Run it from the project root:
 *
 *     php -S 127.0.0.1:3001 tools/revalidate-receiver.php
 *
 * It checks the request exactly as the real endpoint must (timestamp, signature, tag format),
 * then prints the tags it received. The real endpoint does the same, then calls revalidateTag().
 */

header('Content-Type: application/json');

$reply = function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body);

    exit;
};

$secret = '';

foreach (@file(__DIR__.'/../.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (str_starts_with($line, 'REVALIDATE_SECRET=')) {
        $secret = trim(substr($line, strlen('REVALIDATE_SECRET=')), " \t\"'");
    }
}

if (strlen($secret) < 32) {
    $reply(500, ['error' => 'REVALIDATE_SECRET is missing from .env']);
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $path !== '/api/revalidate') {
    $reply(404, ['error' => 'not found']);
}

$body = (string) file_get_contents('php://input');
$timestamp = $_SERVER['HTTP_X_REVALIDATE_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_REVALIDATE_SIGNATURE'] ?? '';

if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
    $reply(401, ['error' => 'stale or missing timestamp']);
}

$expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

if (! hash_equals($expected, $signature)) {
    $reply(401, ['error' => 'bad signature']);
}

$data = json_decode($body, true);
$tags = is_array($data) ? ($data['tags'] ?? null) : null;

if (! is_array($tags) || $tags === [] || count($tags) > 300) {
    $reply(422, ['error' => 'tags must be a list of 1 to 300 items']);
}

foreach ($tags as $tag) {
    if (! is_string($tag) || ! preg_match('/^[a-z0-9][a-z0-9:_-]{0,189}$/', $tag)) {
        $reply(422, ['error' => 'invalid tag']);
    }
}

error_log('REVALIDATE: '.implode(', ', $tags));

$reply(200, ['revalidated' => true, 'tags' => $tags]);