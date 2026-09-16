# Security Policy

## Supported version

Security fixes target the current `7.x` release line.

## Deployment checklist

Matrix AJAX Chat is a self-hosted application. Before exposing it to the Internet:

1. Serve the site over HTTPS.
2. Block direct HTTP access to `/data`, `/src` and `/tests`.
3. Ensure only the PHP/web-server account can read `data/matrix_secret.key`.
4. Keep PHP and the web server updated.
5. Back up the complete `data/` directory securely.
6. Use normal OS/web-server logging and monitor repeated authentication failures.
7. Set conservative reverse-proxy request body limits (10 MiB or lower).
8. Do not treat server-side encryption as end-to-end encryption.

## Attachments

Uploads are checked by server-detected MIME type and stored outside the normal public asset path. Downloads require an authenticated session and conversation access. The download endpoint sends `X-Content-Type-Options: nosniff` and `Content-Disposition: attachment`.

## Encryption

New messages use AES-256-GCM with a random 96-bit nonce and 128-bit authentication tag. The encryption key is derived from a random local server secret. Legacy AES-256-CBC entries remain readable to support upgrades, but new data is not written in that format.

## Reporting

Please report security issues privately to the repository owner instead of publishing exploitable details in a public issue.
