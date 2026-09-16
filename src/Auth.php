<?php
declare(strict_types=1);

namespace MatrixChat;

use PDO;

final class Auth
{
    public function __construct(private PDO $db)
    {
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $secure,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();

        if (!isset($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }

        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public static function csrfToken(): string
    {
        return (string)($_SESSION['csrf_token'] ?? '');
    }

    public static function verifyCsrf(): void
    {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || $token === '' || !hash_equals(self::csrfToken(), $token)) {
            Http::json(['ok' => false, 'error' => 'csrf'], 403);
        }
    }

    /** @return array{name:string,color:string,guest:bool}|null */
    public static function user(): ?array
    {
        if (!isset($_SESSION['matrix_user'])) {
            return null;
        }

        $name = (string)$_SESSION['matrix_user'];
        return [
            'name' => $name,
            'color' => (string)($_SESSION['matrix_color'] ?? '#4cc9ff'),
            'guest' => str_starts_with($name, 'GUEST_'),
        ];
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/^[\p{L}\p{N}_-]{3,24}$/u', $username) === 1
            && !str_starts_with(strtoupper($username), 'GUEST_');
    }

    public function register(string $username, string $password, string $color): ?string
    {
        $username = trim($username);
        if (!self::isValidUsername($username)) {
            return 'username';
        }
        if (strlen($password) < 8 || strlen($password) > 200) {
            return 'password';
        }
        $color = self::sanitizeColor($color);

        $stmt = $this->db->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        if ($stmt->fetchColumn()) {
            return 'exists';
        }

        $stmt = $this->db->prepare(
            'INSERT INTO users (username, password_hash, color, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $color, time()]);
        $this->setUser($username, $color);
        return null;
    }

    public function login(string $username, string $password): bool
    {
        $stmt = $this->db->prepare('SELECT username, password_hash, color FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($password, (string)$row['password_hash'])) {
            return false;
        }

        if (password_needs_rehash((string)$row['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->db->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
            $rehash->execute([password_hash($password, PASSWORD_DEFAULT), $row['username']]);
        }

        $this->setUser((string)$row['username'], self::sanitizeColor((string)$row['color']));
        return true;
    }

    public function guest(): void
    {
        $this->setUser('GUEST_' . strtoupper(bin2hex(random_bytes(4))), '#8ba3c7');
    }

    public function logout(): void
    {
        $user = self::user();
        if ($user) {
            $stmt = $this->db->prepare('DELETE FROM online_status WHERE username = ?');
            $stmt->execute([$user['name']]);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public function updateColor(string $color): string
    {
        $user = self::user();
        if (!$user) {
            throw new \RuntimeException('Authentication required.');
        }

        $color = self::sanitizeColor($color);
        $_SESSION['matrix_color'] = $color;
        if (!$user['guest']) {
            $stmt = $this->db->prepare('UPDATE users SET color = ? WHERE username = ?');
            $stmt->execute([$color, $user['name']]);
        }
        return $color;
    }

    public static function sanitizeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? strtolower($color) : '#4cc9ff';
    }

    private function setUser(string $username, string $color): void
    {
        session_regenerate_id(true);
        $_SESSION['matrix_user'] = $username;
        $_SESSION['matrix_color'] = $color;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
