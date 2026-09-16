<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/matrix-chat-test-' . bin2hex(random_bytes(4));
putenv('MATRIX_CHAT_DATA_DIR=' . $tmp);
mkdir($tmp, 0700, true);

require __DIR__ . '/../src/bootstrap.php';

use MatrixChat\Auth;
use MatrixChat\ChatService;
use MatrixChat\Config;
use MatrixChat\Crypto;
use MatrixChat\Database;
use MatrixChat\UploadService;

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

try {
    $assert(Config::VERSION === '7.0.0', 'version');

    $crypto = new Crypto();
    $plain = "Zażółć gęślą jaźń — Matrix";
    $encrypted = $crypto->encrypt($plain);
    $assert(str_starts_with($encrypted, 'gcm1:'), 'GCM payload marker');
    $assert($crypto->decrypt($encrypted) === $plain, 'GCM roundtrip');
    $assert($crypto->decrypt('broken') === '[corrupted message]', 'corrupted payload is safe');

    $assert(Auth::isValidUsername('Swir_2026'), 'valid username');
    $assert(Auth::isValidUsername('Świr'), 'unicode username');
    $assert(!Auth::isValidUsername('GUEST_deadbeef'), 'reserved guest username');
    $assert(!Auth::isValidUsername('x'), 'short username');
    $assert(Auth::sanitizeColor('#AABBCC') === '#aabbcc', 'color normalization');
    $assert(Auth::sanitizeColor('javascript:') === '#4cc9ff', 'invalid color fallback');

    $db = Database::connect();
    $tables = array_column($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');
    foreach (['users', 'chat_history', 'online_status', 'uploads'] as $table) {
        $assert(in_array($table, $tables, true), "table {$table}");
    }

    $chat = new ChatService($db, $crypto);
    $id = $chat->sendMessage('Alice', '#4cc9ff', 'hello', 'global');
    $assert($id > 0, 'message inserted');
    $messages = $chat->messages('Alice', 'global');
    $assert(count($messages['messages']) === 1, 'message returned');
    $assert($messages['messages'][0]['msg'] === 'hello', 'message decrypted');

    $chat->invite('Alice', 'Bob');
    $bobView = $chat->messages('Bob', 'global');
    $assert(($bobView['invites']['Alice'] ?? '') === 'pending', 'invite pending');
    $chat->respondInvite('Bob', 'Alice', true);
    $aliceView = $chat->messages('Alice', 'global');
    $assert(($aliceView['invites']['Bob'] ?? '') === 'accepted', 'invite accepted');
    $chat->sendMessage('Alice', '#4cc9ff', 'private', 'Bob');
    $private = $chat->messages('Bob', 'Alice');
    $assert(end($private['messages'])['msg'] === 'private', 'private chat after acceptance');

    $assert(UploadService::safeOriginalName("../../bad\r\nname?.pdf") === 'badname_.pdf', 'safe upload name');

    echo "OK {$assertions} assertions\n";
} finally {
    $delete = static function (string $path) use (&$delete): void {
        if (!file_exists($path)) return;
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $item) {
                if ($item === '.' || $item === '..') continue;
                $delete($path . DIRECTORY_SEPARATOR . $item);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    };
    $delete($tmp);
}
