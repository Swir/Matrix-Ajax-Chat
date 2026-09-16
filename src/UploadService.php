<?php
declare(strict_types=1);

namespace MatrixChat;

use PDO;

final class UploadService
{
    public function __construct(private PDO $db, private ChatService $chat)
    {
    }

    /** @param array<string,mixed> $file */
    public function store(array $file, string $sender, string $color, string $target): string
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('upload_error');
        }

        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > Config::MAX_UPLOAD_BYTES) {
            throw new \RuntimeException('upload_size');
        }

        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \RuntimeException('upload_invalid');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $allowed = Config::allowedUploadMimes();
        if (!isset($allowed[$mime])) {
            throw new \RuntimeException('upload_type');
        }

        $original = self::safeOriginalName((string)($file['name'] ?? 'file'));
        $token = bin2hex(random_bytes(24));
        $storedName = bin2hex(random_bytes(24)) . '.' . $allowed[$mime];
        $destination = Config::uploadsDir() . '/' . $storedName;

        if (!move_uploaded_file($tmp, $destination)) {
            throw new \RuntimeException('upload_move');
        }
        @chmod($destination, 0600);

        $stmt = $this->db->prepare(
            'INSERT INTO uploads (token, sender, target, original_name, mime, size, stored_name, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$token, $sender, $target, $original, $mime, $size, $storedName, time()]);

        try {
            $this->chat->sendMessage($sender, $color, '::UPLOAD::' . $token, $target);
        } catch (\Throwable $e) {
            @unlink($destination);
            $this->db->prepare('DELETE FROM uploads WHERE token = ?')->execute([$token]);
            throw $e;
        }

        return $token;
    }

    /** @return array<string,mixed>|null */
    public function findAccessible(string $token, string $currentUser): ?array
    {
        if (preg_match('/^[a-f0-9]{48}$/', $token) !== 1) {
            return null;
        }

        $stmt = $this->db->prepare('SELECT * FROM uploads WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $target = (string)$row['target'];
        $sender = (string)$row['sender'];
        if ($target !== 'global' && !in_array($currentUser, [$target, $sender], true)) {
            return null;
        }

        $path = Config::uploadsDir() . '/' . (string)$row['stored_name'];
        if (!is_file($path)) {
            return null;
        }

        $row['path'] = $path;
        return $row;
    }

    public static function safeOriginalName(string $name): string
    {
        $name = trim(str_replace(["\0", "\r", "\n"], '', basename($name)));
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?? 'file';
        return mb_substr($name === '' ? 'file' : $name, 0, 120);
    }
}
