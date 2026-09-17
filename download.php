<?php
declare(strict_types=1);

use MatrixChat\Auth;

$app = require __DIR__ . '/src/bootstrap.php';
$uploads = $app['uploads'];
$chat = $app['chat'];

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    exit('Authentication required.');
}

$file = null;
$token = (string)($_GET['token'] ?? '');
if ($token !== '') {
    $file = $uploads->findAccessible($token, $user['name']);
} else {
    $legacyMessage = filter_input(INPUT_GET, 'legacy_message', FILTER_VALIDATE_INT);
    if (is_int($legacyMessage) && $legacyMessage > 0) {
        $file = $chat->legacyAttachmentForMessage($legacyMessage, $user['name']);
        if ($file) {
            $file['original_name'] = $file['name'];
        }
    }
}

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$filename = (string)($file['original_name'] ?? 'file');
$mime = (string)$file['mime'];
$inlineRequested = ($_GET['inline'] ?? '') === '1';
$inlineAllowed = $inlineRequested && str_starts_with($mime, 'image/');
$disposition = $inlineAllowed ? 'inline' : 'attachment';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize((string)$file['path']));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($filename, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile((string)$file['path']);
