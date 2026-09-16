# Changelog

## 7.0.0 - 2026-09-17

### Added
- modular PHP architecture with dedicated authentication, chat, crypto, database, upload and HTTP services
- responsive Matrix Blue interface for desktop and mobile
- Polish/English automatic language selection
- PWA manifest, service worker and dedicated project artwork
- 192x192 and 512x512 application icons
- authenticated AES-256-GCM encryption for newly stored messages
- safe legacy AES-256-CBC reader for existing databases
- non-destructive legacy database/key migration
- upload metadata table and authenticated download endpoint
- message/invite/upload/auth rate limits
- GitHub Actions CI on PHP 8.1-8.4
- versioned web deployment ZIP and SHA256 release workflow
- lightweight PHP test suite

### Changed
- runtime database, key and uploads moved under `data/`
- uploads are no longer directly web-addressable
- maximum retained message history increased to 500
- online presence window increased to 25 seconds for unstable/mobile connections
- old monolithic `index.php` replaced by a small entry point

### Security
- authenticated encryption detects message tampering
- CSP, clickjacking, MIME-sniffing, referrer and permissions headers
- hardened session cookie configuration
- stronger username/password validation
- randomized non-public upload storage
