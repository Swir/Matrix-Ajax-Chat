<?php
declare(strict_types=1);

namespace MatrixChat;

final class Crypto
{
    private string $key;

    public function __construct()
    {
        $this->key = hash('sha256', $this->loadSecret(), true);
    }

    public function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new \RuntimeException('Unable to encrypt message.');
        }

        return 'gcm1:' . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        if (str_starts_with($payload, 'gcm1:')) {
            return $this->decryptGcm(substr($payload, 5));
        }

        // Backward compatibility with the legacy AES-256-CBC database format.
        return $this->decryptLegacyCbc($payload);
    }

    private function decryptGcm(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 29) {
            return '[corrupted message]';
        }

        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $ciphertext = substr($data, 28);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return $plaintext === false ? '[corrupted message]' : $plaintext;
    }

    private function decryptLegacyCbc(string $encoded): string
    {
        $data = base64_decode($encoded, true);
        if ($data === false || strlen($data) < 17) {
            return '[corrupted message]';
        }

        $iv = substr($data, 0, 16);
        $ciphertext = substr($data, 16);
        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-cbc',
            $this->key,
            OPENSSL_RAW_DATA,
            $iv
        );

        return $plaintext === false ? '[corrupted message]' : $plaintext;
    }

    private function loadSecret(): string
    {
        $path = Config::secretPath();
        if (!is_file($path)) {
            $secret = bin2hex(random_bytes(32));
            if (file_put_contents($path, $secret, LOCK_EX) === false) {
                throw new \RuntimeException('Unable to create encryption key.');
            }
            @chmod($path, 0600);
            return $secret;
        }

        $secret = trim((string) file_get_contents($path));
        if (strlen($secret) < 32) {
            throw new \RuntimeException('Encryption key is invalid.');
        }

        return $secret;
    }
}
