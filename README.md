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

## Matrix AJAX Chat v7.1

v7.1 is a regression-recovery release built on the modular v7 architecture. It restores useful terminal behavior from the classic single-file build without reintroducing direct public upload paths or weakening the v7 security model.

### Restored from the classic build

- terminal commands: `/help`, `/ping`, `/clear`, `/whoami`
- live request latency in the connection indicator
- optional terminal sounds for messages/invites with a persistent mute toggle
- fullscreen control
- live message character counter and local typing/upload status indicators
- accepted private-conversation shortcuts that remain visible when a peer goes offline
- original attachment filenames
- authenticated inline previews for image attachments
- authenticated compatibility with legacy `::FILE_TAG::matrix_uploads/...` attachment records

### Current feature set

- Matrix Blue responsive desktop/mobile interface
- animated Matrix background
- global chat and invitation-based private conversations
- Polish/English auto-detection with a manual language switch
- registered accounts plus guest access
- per-user signature color, editable after login
- SQLite WAL mode and indexed tables
- online presence with stale-session cleanup
- authenticated AES-256-GCM encryption for newly stored messages
- legacy AES-256-CBC message decryption for upgrade compatibility
- CSRF protection, hardened session cookies and regenerated session IDs
- per-session rate limiting for authentication, messages, invites and uploads
- safe downloads from non-public storage rather than direct upload URLs
- MIME + size validation for JPG/PNG/GIF/WebP/PDF/ZIP/TXT
- PWA manifest, service worker, SVG favicon and 192/512 PNG icons
- versioned static cache that refreshes upgraded JS/CSS instead of pinning old UI assets
- CI on PHP 8.1, 8.2, 8.3 and 8.4
- versioned deploy ZIP + SHA256 in GitHub Releases

## Architecture

```text
index.php              HTML entry point + authentication forms
api.php                JSON/AJAX endpoint + safe attachment metadata
download.php           authorized modern/legacy attachment downloads
src/
  Auth.php              sessions, CSRF, login/register/guest, user color
  ChatService.php       messages, presence, private invitations, legacy attachment checks
  Crypto.php            AES-256-GCM + legacy CBC reader
  Database.php          SQLite initialization/migrations
  UploadService.php     validated non-public file storage
  I18n.php              Polish/English UI strings
  Http.php              JSON and security headers
  RateLimiter.php       lightweight abuse controls
assets/
  app.css               responsive Matrix Blue UI
  app.js                polling, commands, sounds, composer, invites, PWA setup
  matrix-chat.svg       application/project icon
  icon-192.png          PWA icon
  icon-512.png          PWA icon
data/                   database, key and new uploads (runtime only)
matrix_uploads/         legacy v6 uploads only, if upgrading an existing install
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

For production, point Apache/Nginx at the repository directory and **block direct HTTP access to `/data`, `/src`, `/tests` and any preserved legacy `/matrix_uploads` directory**. Attachments are served through `download.php` after authorization checks.

Example Nginx protection:

```nginx
location ~ ^/(data|src|tests|matrix_uploads)/ {
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
- v7.1 can display/download old attachment messages securely when their original files are still present in `matrix_uploads/`.

**Important:** if the old installation contains `matrix_uploads/`, keep that folder beside `index.php` until you no longer need historical attachments. Do not expose the directory directly through the web server; v7.1 resolves legacy attachments through authenticated `download.php` requests.

After verifying the upgraded installation and making a backup, legacy root-level database/key files can be removed. Keep `matrix_uploads/` only for as long as historical attachment access is required.

## Terminal commands

| Command | Action |
|---|---|
| `/help` | Show available terminal commands |
| `/ping` | Show latest measured AJAX latency |
| `/clear` | Clear already displayed messages for the current channel in this browser session |
| `/whoami` | Show current user and channel/node |

## Attachments

New uploads are renamed internally, stored under `data/uploads/`, and are never linked directly. The message API returns only safe metadata such as the original filename, MIME type and size. Images can be previewed inline through an authenticated endpoint; other files download through the same authorization layer.

Legacy attachments are revalidated before serving. The server checks the historical message, verifies the requesting user may see that conversation, rejects path traversal, detects the MIME type again and never exposes the filesystem path.

## Storage and backups

Back up the complete `data/` directory. It contains the SQLite database, server encryption key and new attachments. If upgrading from v6, also back up `matrix_uploads/` while historical attachments are still needed. Losing the encryption key makes encrypted message history unreadable.

This is **encryption at rest**, not end-to-end encryption: the server can decrypt stored messages to deliver them.

## Development

```bash
php tests/run.php
find . -name '*.php' -not -path './data/*' -exec php -l {} \;
```

The regression suite checks cryptography, username/color validation, SQLite migrations, global/private flows, modern attachment authorization, legacy attachment parsing/access and filename sanitization.

## Release

A merge commit containing `[release]` triggers the web release workflow. It runs tests, creates a deployable ZIP and publishes its SHA256 checksum.

## Security

Read [SECURITY.md](SECURITY.md) before public deployment. Use HTTPS, restrict runtime/source directories, keep PHP updated, back up the encryption key, and review reverse-proxy/web-server configuration.

## Author

Developed by **Swir** — https://github.com/Swir
