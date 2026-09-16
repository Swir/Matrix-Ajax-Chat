<?php
declare(strict_types=1);

namespace MatrixChat;

final class I18n
{
    /** @var array<string,array<string,string>> */
    private const STRINGS = [
        'pl' => [
            'title' => 'Matrix AJAX Chat',
            'subtitle' => 'Szyfrowany lokalny terminal rozmów',
            'login' => 'Zaloguj',
            'register' => 'Utwórz konto',
            'guest' => 'Wejdź jako gość',
            'username' => 'Nazwa użytkownika',
            'password' => 'Hasło (min. 8 znaków)',
            'color' => 'Kolor sygnatury',
            'global' => 'Kanał globalny',
            'online' => 'Online',
            'message' => 'Napisz wiadomość…',
            'send' => 'Wyślij',
            'attach' => 'Plik',
            'logout' => 'Wyloguj',
            'back_global' => 'Globalny',
            'invite' => 'Zaproś',
            'pending' => 'Oczekuje',
            'accepted' => 'Prywatny',
            'declined' => 'Odrzucono',
            'accept' => 'Akceptuj',
            'decline' => 'Odrzuć',
            'empty' => 'Brak wiadomości.',
            'auth_error' => 'Nieprawidłowy login lub hasło.',
            'register_error' => 'Nie udało się utworzyć konta. Sprawdź nazwę i hasło.',
            'version' => 'Wersja',
        ],
        'en' => [
            'title' => 'Matrix AJAX Chat',
            'subtitle' => 'Encrypted local conversation terminal',
            'login' => 'Sign in',
            'register' => 'Create account',
            'guest' => 'Continue as guest',
            'username' => 'Username',
            'password' => 'Password (8+ characters)',
            'color' => 'Signature color',
            'global' => 'Global channel',
            'online' => 'Online',
            'message' => 'Write a message…',
            'send' => 'Send',
            'attach' => 'File',
            'logout' => 'Sign out',
            'back_global' => 'Global',
            'invite' => 'Invite',
            'pending' => 'Pending',
            'accepted' => 'Private',
            'declined' => 'Declined',
            'accept' => 'Accept',
            'decline' => 'Decline',
            'empty' => 'No messages yet.',
            'auth_error' => 'Invalid username or password.',
            'register_error' => 'Account could not be created. Check username and password.',
            'version' => 'Version',
        ],
    ];

    public static function detect(): string
    {
        if (isset($_GET['lang']) && in_array($_GET['lang'], ['pl', 'en'], true)) {
            $_SESSION['lang'] = $_GET['lang'];
        }

        if (isset($_SESSION['lang']) && in_array($_SESSION['lang'], ['pl', 'en'], true)) {
            return (string)$_SESSION['lang'];
        }

        $header = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        $lang = str_starts_with($header, 'pl') ? 'pl' : 'en';
        $_SESSION['lang'] = $lang;
        return $lang;
    }

    /** @return array<string,string> */
    public static function all(string $lang): array
    {
        return self::STRINGS[$lang] ?? self::STRINGS['en'];
    }
}
