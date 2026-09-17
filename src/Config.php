<?php
declare(strict_types=1);

namespace MatrixChat;

final class Config
{
    public const VERSION = '7.1.0';
    public const HISTORY_LIMIT = 500;
    public const ONLINE_TTL_SECONDS = 25;
    public const MAX_MESSAGE_LENGTH = 1000;
    public const MAX_UPLOAD_BYTES = 10_485_760; // 10 MiB

    public static function rootDir(): string
    {
        return dirname(__DIR__);
    }

    public static function dataDir(): string
    {
        $override = getenv('MATRIX_CHAT_DATA_DIR');
        return $override !== false && $override !== '' ? rtrim($override, DIRECTORY_SEPARATOR) : self::rootDir() . '/data';
    }

    public static function databasePath(): string
    {
        return self::dataDir() . '/matrix.sqlite';
    }

    public static function secretPath(): string
    {
        return self::dataDir() . '/matrix_secret.key';
    }

    public static function uploadsDir(): string
    {
        return self::dataDir() . '/uploads';
    }

    public static function legacyUploadsDir(): string
    {
        $override = getenv('MATRIX_CHAT_LEGACY_UPLOAD_DIR');
        return $override !== false && $override !== ''
            ? rtrim($override, DIRECTORY_SEPARATOR)
            : self::rootDir() . '/matrix_uploads';
    }

    /** @return array<string,string> */
    public static function allowedUploadMimes(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'application/pdf' => 'pdf',
            'application/zip' => 'zip',
            'text/plain' => 'txt',
        ];
    }
}
