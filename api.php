<?php
declare(strict_types=1);

use MatrixChat\Auth;
use MatrixChat\Http;
use MatrixChat\RateLimiter;

$app = require __DIR__ . '/src/bootstrap.php';
$auth = $app['auth'];
$chat = $app['chat'];
$uploads = $app['uploads'];

$user = Auth::user();
if (!$user) {
    Http::json(['ok' => false, 'error' => 'auth_required'], 401);
}

$chat->touchPresence($user['name']);
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');

try {
    if ($action === 'online') {
        Http::json(['ok' => true, 'users' => $chat->onlineUsers($user['name'])]);
    }

    if ($action === 'messages') {
        $target = (string)($_GET['target'] ?? 'global');
        Http::json(['ok' => true] + $chat->messages($user['name'], $target));
    }

    Http::requireMethod('POST');
    Auth::verifyCsrf();

    if ($action === 'presence') {
        Http::json(['ok' => true]);
    }

    if ($action === 'send') {
        if (!RateLimiter::hit('send', 30, 30)) {
            Http::json(['ok' => false, 'error' => 'rate_limit'], 429);
        }

        $id = $chat->sendMessage(
            $user['name'],
            $user['color'],
            (string)($_POST['message'] ?? ''),
            (string)($_POST['target'] ?? 'global')
        );
        Http::json(['ok' => true, 'id' => $id]);
    }

    if ($action === 'invite') {
        if (!RateLimiter::hit('invite', 12, 60)) {
            Http::json(['ok' => false, 'error' => 'rate_limit'], 429);
        }

        $chat->invite($user['name'], (string)($_POST['target'] ?? ''));
        Http::json(['ok' => true]);
    }

    if ($action === 'respond') {
        $chat->respondInvite(
            $user['name'],
            (string)($_POST['target'] ?? ''),
            (string)($_POST['decision'] ?? '') === 'accept'
        );
        Http::json(['ok' => true]);
    }

    if ($action === 'color') {
        $color = $auth->updateColor((string)($_POST['color'] ?? ''));
        Http::json(['ok' => true, 'color' => $color]);
    }

    if ($action === 'upload') {
        if (!RateLimiter::hit('upload', 8, 60)) {
            Http::json(['ok' => false, 'error' => 'rate_limit'], 429);
        }

        $token = $uploads->store(
            $_FILES['file'] ?? [],
            $user['name'],
            $user['color'],
            (string)($_POST['target'] ?? 'global')
        );
        Http::json(['ok' => true, 'token' => $token]);
    }

    Http::json(['ok' => false, 'error' => 'unknown_action'], 404);
} catch (\InvalidArgumentException $e) {
    Http::json(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (\RuntimeException $e) {
    $code = $e->getMessage() === 'private_not_accepted' ? 403 : 422;
    Http::json(['ok' => false, 'error' => $e->getMessage()], $code);
} catch (\Throwable $e) {
    error_log('[MatrixChat] ' . $e->getMessage());
    Http::json(['ok' => false, 'error' => 'server_error'], 500);
}
