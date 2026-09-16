<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'MatrixChat\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use MatrixChat\Auth;
use MatrixChat\ChatService;
use MatrixChat\Crypto;
use MatrixChat\Database;
use MatrixChat\Http;
use MatrixChat\UploadService;

Auth::startSession();
Http::securityHeaders();

$db = Database::connect();
$crypto = new Crypto();
$auth = new Auth($db);
$chat = new ChatService($db, $crypto);
$uploads = new UploadService($db, $chat);

return [
    'db' => $db,
    'crypto' => $crypto,
    'auth' => $auth,
    'chat' => $chat,
    'uploads' => $uploads,
];
