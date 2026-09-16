<?php
declare(strict_types=1);

use MatrixChat\Auth;

$app = require __DIR__ . '/src/bootstrap.php';
$uploads = $app['uploads'];

$user = Auth::user();
if (!$user) {
    http_response_code(401);
    exit('Authentication required.');
}

$token = (string)($_GET['token'] ?? '');
$file = $uploads->findAccessible($token, $user['name']);
if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

$filename = (string)$file['original_name'];
header('Content-Type: ' . (string)$file['mime']);
header('Content-Length: ' . (string)filesize((string)$file['path']));
header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\"\\") . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile((string)$file['path']);
