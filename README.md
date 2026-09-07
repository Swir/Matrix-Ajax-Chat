<div align="center">

# 🟢 Matrix AJAX Chat

**Single-file PHP chat with a Matrix-inspired interface, SQLite storage and asynchronous messaging**  
**Jednoplikowy czat PHP w stylistyce Matrix z SQLite i komunikacją asynchroniczną**

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/Database-SQLite-003B57?logo=sqlite)
![Security](https://img.shields.io/badge/Security-CSRF%20%7C%20Sessions-success)
![Style](https://img.shields.io/badge/UI-Matrix-00ff66)
![Author](https://img.shields.io/badge/Author-Swir-ff4fa3)

</div>

---

## 🇬🇧 English

Matrix AJAX Chat is an experimental web chat packed into a single `index.php` file. It combines PHP sessions, SQLite persistence, asynchronous requests and a Matrix-inspired UI. The application supports global and private conversation flows and keeps online-presence information locally.

It can be useful for users searching for a **PHP AJAX chat**, **SQLite chat application**, **single-file PHP chat**, **private messaging system**, or a lightweight Matrix-style web chat project.

### ✨ Features
- Matrix-inspired interface
- PHP + SQLite architecture
- user accounts and sessions
- CSRF protection
- regenerated session identifiers
- global chat and private-message flow
- online-user tracking
- server-side message storage
- AES-256-CBC encryption for stored message payloads using a locally generated server secret
- automatic history trimming

### 🛠 Requirements
- PHP with PDO SQLite support
- OpenSSL PHP extension
- writable application directory for the SQLite database and local server secret
- web server such as Apache or Nginx

### 🚀 Quick start
Copy `index.php` to a PHP-enabled web directory and ensure PHP can write to that directory. The application creates its local database and server-secret file when needed.

---

## 🇵🇱 Polski

Matrix AJAX Chat to eksperymentalny czat internetowy zamknięty w jednym pliku `index.php`. Łączy sesje PHP, bazę SQLite, komunikację asynchroniczną oraz interfejs inspirowany Matrixem. Obsługuje rozmowy globalne i prywatne oraz lokalne śledzenie aktywnych użytkowników.

Projekt może zainteresować osoby szukające **czatu PHP AJAX**, **aplikacji czatowej SQLite**, **jednoplikowego czatu PHP**, prywatnych wiadomości albo lekkiego czatu webowego w stylistyce Matrix.

### ✨ Funkcje
- interfejs inspirowany Matrixem
- PHP + SQLite
- konta użytkowników i sesje
- ochrona CSRF
- regeneracja identyfikatora sesji
- czat globalny i rozmowy prywatne
- lista użytkowników online
- lokalna historia wiadomości
- szyfrowanie zapisanych treści AES-256-CBC przy użyciu lokalnego sekretu serwera
- automatyczne ograniczanie historii

### 🛠 Wymagania
- PHP z PDO SQLite
- rozszerzenie OpenSSL
- możliwość zapisu w katalogu aplikacji
- Apache, Nginx lub inny serwer obsługujący PHP

### 🚀 Szybki start
Umieść `index.php` w katalogu serwera WWW z obsługą PHP i zapewnij aplikacji możliwość zapisu w katalogu. Baza SQLite i lokalny sekret serwera zostaną utworzone w razie potrzeby.

---

## 🔎 Discoverability / Keywords

`php chat` · `ajax chat` · `sqlite chat` · `private messaging` · `single file php` · `matrix ui` · `web chat` · `php sqlite` · `csrf` · `sessions`

## 🔐 Security / Bezpieczeństwo
This is an experimental project. Before exposing it publicly, review server permissions, HTTPS configuration, authentication policy, backups and application security for your deployment environment.

To projekt eksperymentalny. Przed wystawieniem go publicznie sprawdź uprawnienia serwera, HTTPS, politykę uwierzytelniania, kopie zapasowe oraz bezpieczeństwo całego środowiska.

## 👤 Author / Autor
Developed by **Swir**.
