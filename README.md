<div align="center">

# 🟢 Matrix AJAX Chat

### Single-File PHP Chat with SQLite, Private Messaging & Matrix UI

**PHP • AJAX • SQLite • Sessions • CSRF Protection • File Sharing**

![PHP](https://img.shields.io/badge/PHP-8.x-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/Database-SQLite-003B57?logo=sqlite&logoColor=white)
![Security](https://img.shields.io/badge/Security-CSRF%20%7C%20Sessions-success)
![UI](https://img.shields.io/badge/UI-Matrix-00ff66)
![Architecture](https://img.shields.io/badge/Architecture-Single%20File-111111)

</div>

---

## 🚀 About

**Matrix AJAX Chat** is an experimental web chat application packed into a single `index.php` file. It combines PHP sessions, SQLite persistence, asynchronous requests and a Matrix-inspired interface in a compact deployment model.

The application includes global chat, private conversation flows, online-user tracking, account handling and controlled file uploads. Stored message payloads are encrypted server-side before being written to the local SQLite database.

It is useful for users searching for a **PHP AJAX chat**, **SQLite chat application**, **single-file PHP chat**, **private messaging system**, **Matrix style web chat** or a compact self-hosted chat project.

---

## ✨ Features

| Feature | Description |
|---|---|
| 🟢 Matrix UI | Terminal-inspired Matrix visual style |
| 💬 Global chat | Shared public conversation channel |
| 🔐 Private messaging | Private conversation invitation / acceptance flow |
| 👥 User accounts | Session-based registered users and guest mode |
| 🟩 Online presence | Tracks recently active users |
| 🗄️ SQLite | Local database with no external DB server required |
| 🛡️ CSRF protection | CSRF token verification for write actions |
| 🔄 Session hardening | Session ID regeneration |
| 🔒 Stored-message encryption | AES-256-CBC encryption of message payloads at rest |
| 📎 File sharing | Controlled uploads for selected file types |
| 🧹 History management | Automatically limits retained message history |
| ⚡ AJAX workflow | Messages and presence update asynchronously |

---

## 🧠 Architecture

```text
Browser
   │
   ├── AJAX requests
   ▼
index.php
   │
   ├── Sessions / CSRF
   ├── Message encryption
   ├── Upload validation
   ▼
SQLite Database
```

The application creates its local SQLite database and server-secret file when required.

---

## 📋 Requirements

- PHP 8.x recommended
- PDO SQLite extension
- OpenSSL PHP extension
- Fileinfo extension for upload MIME validation
- Writable application directory
- Apache, Nginx or another PHP-capable web server

---

## 📦 Quick Start

```bash
git clone https://github.com/Swir/Matrix-Ajax-Chat.git
```

Copy `index.php` to a PHP-enabled web directory and ensure PHP has permission to create/write the local database, secret file and upload directory.

For development you can also use PHP's built-in server:

```bash
php -S localhost:8080
```

Then open:

```text
http://localhost:8080
```

---

## 🔐 Security Notes

This is an experimental self-hosted project, not a security-audited messaging platform. Before exposing it to the public Internet, review HTTPS, server permissions, authentication requirements, upload policy, backups, rate limiting and deployment-specific security controls.

Encryption at rest protects stored message payloads from casual database inspection, but it is not presented as end-to-end encryption.

---

## 🌍 Language

The current application interface is primarily Polish. A separate English variant can be maintained without replacing the original Polish build.

---

## 🔍 Discoverability

`php ajax chat` • `php sqlite chat` • `single file php chat` • `matrix chat php` • `private messaging php` • `self hosted chat php` • `ajax messaging app` • `sqlite web chat` • `php chat source code` • `matrix style web app`

---

## 👨‍💻 Author

Developed by **Swir** — [@Swir](https://github.com/Swir)

<div align="center">

### 🟢 PHP + SQLite + Matrix vibes

⭐ **Star the repository if you like the project!**

</div>
