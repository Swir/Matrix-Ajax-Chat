<div align="center">

<img src="assets/matrix-chat.svg" width="112" alt="Matrix AJAX Chat icon">

# Matrix AJAX Chat

### Self-hosted PHP + SQLite chat rebuilt as a secure, modular Matrix Blue web app

**PHP 8.1+ · SQLite · AJAX · PWA · PL/EN · no framework required**

[![CI](https://github.com/Swir/Matrix-Ajax-Chat/actions/workflows/ci.yml/badge.svg)](https://github.com/Swir/Matrix-Ajax-Chat/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/Swir/Matrix-Ajax-Chat)](https://github.com/Swir/Matrix-Ajax-Chat/releases)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Author](https://img.shields.io/badge/by-Swir-4cc9ff)](https://github.com/Swir)

</div>

## What changed in v7

The old 47 KB single-file application has been replaced with a small bootstrap plus focused classes for authentication, storage, encryption, chat logic, uploads and HTTP handling. Static UI code now lives under `assets/`, runtime data is isolated under `data/`, and GitHub Actions validates every supported PHP version before a release is published.

### Highlights

- Matrix Blue responsive desktop/mobile interface
- global chat and invitation-based private conversations
- Polish/English auto-detection with a manual language switch
- registered accounts plus guest access
- SQLite WAL mode and indexed tables
- authenticated AES-256-GCM encryption for stored message payloads
- legacy AES-256-CBC message decryption for upgrade compatibility
- CSRF protection, hardened session cookies and regenerated session IDs
- per-session rate limiting for authentication, messages, invites and uploads
- safe downloads from non-public storage rather than direct upload URLs
- MIME + size validation for JPG/PNG/GIF/WebP/PDF/ZIP/TXT
- online presence with automatic stale-session cleanup
- PWA manifest, service worker, SVG favicon and 192/512 PNG icons
- CI on PHP 8.1, 8.2, 8.3 and 8.4
- versioned deploy ZIP + SHA256 in GitHub Releases

## Architecture

```text
index.php              HTML entry point + authentication forms
api.php                JSON/AJAX endpoint
download.php           authorized attachment downloads
src/
  Auth.php              sessions, CSRF, login/register/guest
  ChatService.php       messages, presence, private invitations
  Crypto.php            AES-256-GCM + legacy CBC reader
  Database.php          SQLite initialization/migrations
  UploadService.php     validated non-public file storage
  I18n.php              Polish/English UI strings
  Http.php              JSON and security headers
  RateLimiter.php       lightweight abuse controls
assets/
  app.css               responsive Matrix Blue UI
  app.js                polling, composer, invites, PWA setup
  matrix-chat.svg       project icon
data/                   database, key and uploads (runtime only)
```

## Requirements

- PHP **8.1+**
- extensions: `pdo_sqlite`, `openssl`, `fileinfo`, `mbstring`
- HTTPS strongly recommended for Internet-facing deployments
- write permission for the `data/` directory

## Install

```bash
git clone https://github.com/Swir/Matrix-Ajax-Chat.git
cd Matrix-Ajax-Chat
php -S 127.0.0.1:8080
```

Open `http://127.0.0.1:8080`.

For production, point Apache/Nginx at the repository directory and **block direct HTTP access to `/data`, `/src` and `/tests`**. The included `data/.htaccess` blocks `/data` on Apache, but Nginx needs an explicit location rule.

Example Nginx protection:

```nginx
location ~ ^/(data|src|tests)/ {
    deny all;
    return 404;
}
```

## Upgrade from the legacy single-file build

v7 performs a non-destructive migration on first start:

- `matrix_database.db` is copied to `data/matrix.sqlite` when no v7 database exists.
- `matrix_secret.key` is copied to `data/matrix_secret.key`.
- old CBC-encrypted messages remain readable.
- new messages use authenticated AES-256-GCM.

After verifying the v7 install and backing up your data, remove legacy root-level runtime files from the web directory.

## Storage and backups

Back up the complete `data/` directory. It contains the SQLite database, server encryption key and attachments. Losing the key makes encrypted message history unreadable.

This encryption is **encryption at rest**, not end-to-end encryption: the server can decrypt stored messages to deliver them.

## Development

```bash
php tests/run.php
find . -name '*.php' -not -path './data/*' -exec php -l {} \;
```

The test suite checks cryptography, username/color validation, SQLite migrations, public/private message flows and filename sanitization.

## Release

A merge commit containing `[release]` triggers the web release workflow. It runs tests, creates a deployable ZIP and publishes its SHA256 checksum.

## Security

Read [SECURITY.md](SECURITY.md) before public deployment. Use HTTPS, restrict `/data`, keep PHP updated, back up the encryption key, and review your reverse proxy/web-server configuration.

## Author

Developed by **Swir** — https://github.com/Swir
