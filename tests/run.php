<?php
declare(strict_types=1);

$tmp = sys_get_temp_dir() . '/matrix-chat-test-' . bin2hex(random_bytes(4));
putenv('MATRIX_CHAT_DATA_DIR=' . $tmp);
putenv('MATRIX_CHAT_LEGACY_UPLOAD_DIR=' . $tmp . '/legacy_uploads');
mkdir($tmp, 0700, true);
mkdir($tmp . '/legacy_uploads', 0700, true);

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
    $assert(Config::VERSION === '7.1.0', 'version');

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

    $token = str_repeat('a', 48);
    $stored = 'stored.txt';
    file_put_contents(Config::uploadsDir() . '/' . $stored, 'hello upload');
    $stmt = $db->prepare(
        'INSERT INTO uploads (token, sender, target, original_name, mime, size, stored_name, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$token, 'Alice', 'Bob', 'notes.txt', 'text/plain', 12, $stored, time()]);
    $uploads = new UploadService($db, $chat);
    $assert($uploads->findAccessible($token, 'Alice') !== null, 'sender can access modern upload');
    $assert($uploads->findAccessible($token, 'Bob') !== null, 'private recipient can access modern upload');
    $assert($uploads->findAccessible($token, 'Mallory') === null, 'third party cannot access private upload');

    $legacyPath = Config::legacyUploadsDir() . '/PAK_demo.txt';
    file_put_contents($legacyPath, 'legacy attachment');
    $legacyId = $chat->sendMessage(
        'Alice',
        '#4cc9ff',
        '::FILE_TAG::matrix_uploads/PAK_demo.txt::legacy-notes.txt::txt',
        'global'
    );
    $legacy = $chat->legacyAttachmentForMessage($legacyId, 'Bob');
    $assert($legacy !== null, 'legacy attachment marker resolves');
    $assert(($legacy['name'] ?? '') === 'legacy-notes.txt', 'legacy original filename preserved safely');
    $assert(($legacy['mime'] ?? '') === 'text/plain', 'legacy MIME revalidated from file');
    $assert(ChatService::parseLegacyAttachmentMarker('::FILE_TAG::matrix_uploads/../secret::x.txt::txt') === null, 'legacy traversal rejected');
    $assert(ChatService::parseLegacyAttachmentMarker('normal message') === null, 'normal message is not attachment');

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
