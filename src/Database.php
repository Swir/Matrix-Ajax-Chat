<?php
declare(strict_types=1);

namespace MatrixChat;

use PDO;

final class Database
{
    public static function connect(): PDO
    {
        self::prepareStorage();

        $pdo = new PDO('sqlite:' . Config::databasePath(), null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        self::migrate($pdo);
        return $pdo;
    }

    private static function prepareStorage(): void
    {
        foreach ([Config::dataDir(), Config::uploadsDir()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new \RuntimeException('Unable to create application data directory.');
            }
        }

        // Non-destructive upgrade path from the legacy single-file release.
        $legacyDb = Config::rootDir() . '/matrix_database.db';
        if (!is_file(Config::databasePath()) && is_file($legacyDb)) {
            @copy($legacyDb, Config::databasePath());
        }

        $legacySecret = Config::rootDir() . '/matrix_secret.key';
        if (!is_file(Config::secretPath()) && is_file($legacySecret)) {
            @copy($legacySecret, Config::secretPath());
        }
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE,
                password_hash TEXT NOT NULL,
                color TEXT NOT NULL DEFAULT "#4cc9ff",
                created_at INTEGER NOT NULL DEFAULT 0
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp TEXT NOT NULL,
                sender TEXT NOT NULL,
                color TEXT NOT NULL,
                encrypted_msg TEXT NOT NULL,
                target TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS online_status (
                username TEXT PRIMARY KEY,
                last_seen INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS uploads (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                token TEXT NOT NULL UNIQUE,
                sender TEXT NOT NULL,
                target TEXT NOT NULL,
                original_name TEXT NOT NULL,
                mime TEXT NOT NULL,
                size INTEGER NOT NULL,
                stored_name TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );

        // Legacy databases do not have created_at on users.
        $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
        $hasCreatedAt = false;
        foreach ($columns as $column) {
            if (($column['name'] ?? '') === 'created_at') {
                $hasCreatedAt = true;
                break;
            }
        }
        if (!$hasCreatedAt) {
            $pdo->exec('ALTER TABLE users ADD COLUMN created_at INTEGER NOT NULL DEFAULT 0');
        }

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_chat_target_id ON chat_history(target, id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_presence_last_seen ON online_status(last_seen)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_upload_token ON uploads(token)');
    }
}
