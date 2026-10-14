# Changelog

All notable changes to b2-browser. Versions follow [Semantic Versioning](https://semver.org/).

## v2.0.0 — 2026-10

A ground-up refactor. **Breaking:** new file layout, configuration format and
login method — see [UPGRADING.md](UPGRADING.md).

### Security
- **Removed the `?stream=` endpoint.** It accepted HTTP Basic credentials without MFA and did not count failures, so it allowed unlimited password guessing and a second-factor bypass. Playback has used signed B2 URLs since v1.3; nothing linked to it.
- **Lockout now lasts the configured duration.** The window purge deleted locked entries 300 s after the first failure, cutting a 15-minute lockout to about 5 minutes.
- **Folder listing is confined to the prefix.** `?path=` previously bypassed `B2_ALLOWED_PREFIX`, exposing every object name in the bucket. The prefix is also normalised to end in `/`, so `camera-footage` no longer matches `camera-footage-private/`.
- **Restricted B2 key enforced.** On start-up the app inspects the key's `allowed` block (bucket, `namePrefix`, capabilities) and refuses to run with a key that can write or delete, or is not limited to the configured bucket and prefix (`b2.enforce_key_scope`).
- **Fails closed without credentials.** v1.x silently served the bucket publicly if `AUTH_USERNAME`/`AUTH_PASSWORD` were empty; that now requires an explicit `auth.allow_no_auth`.
- **Form login replaces HTTP Basic Auth.** Sign-out is real (Basic credentials were cached and resent by the browser), logout is POST + CSRF, and both login steps count towards the lockout. The failure counter is cleared only after both factors succeed.
- **Passwords stored as `password_hash()`** (`bin/hash-password.php`); plaintext is rejected. Usernames cannot be enumerated by timing.
- **TOTP:** check-and-consume of a time step is one atomic operation (two simultaneous submissions of a code could both pass before); codes compared with `hash_equals`; new secrets are 160-bit; enrolment is done on the server with `bin/totp-setup.php`, and the web setup page is off by default.
- **Sessions:** `Secure`, `HttpOnly`, `SameSite=Strict` cookie set in code, `use_strict_mode`, an absolute lifetime in addition to the idle timeout, and session ID rotation at each login step.
- **Rate limiting:** IPv6 clients are grouped by /64 (configurable); state-file errors fail closed; lockout and failed-login alerts fire once instead of on every blocked request.
- **CSP tightened:** `default-src 'none'`, `script-src 'self'` with no inline script at all (no nonce needed), `base-uri 'none'`, and `img-src`/`media-src` limited to this account's B2 download host rather than `*.backblazeb2.com`. Added HSTS, COOP and CORP headers, `Cache-Control: no-store` on pages, and a sandbox CSP on downloads.
- **QR code library vendored** (qrcode-generator 2.0.4, MIT) instead of loaded from a CDN without an SRI hash.
- All output escaped, including date/camera metadata that v1.x echoed raw. B2 error bodies are no longer shown to visitors.

### Fixed
- Object names are percent-encoded in B2 URLs; names with spaces, `#` or `?` failed before.
- Downloads check B2's status: a missing file now gives a 404 page instead of B2's JSON error saved as the file.
- RFC 6266 `Content-Disposition` with a UTF-8 `filename*`.
- Downloads release the session lock, so other tabs stay usable during long transfers.
- An expired B2 account token is refreshed once automatically.
- Hide markers and unfinished large-file uploads are no longer listed.
- Date/camera metadata is found wherever the `YYYY/MM/DD` run sits in the path instead of at fixed positions.

### Added
- **Jump to date** picker (`media.date_path_format`, default `{Y}/{m}/{d}/`).
- **Image thumbnails**, all authorised by a single download token per folder.
- **Previous page** navigation and page numbers in URLs instead of raw object names.
- Configurable **video and image extension lists** (`mp4`, `m4v`, `mov`, `webm`, `jpg`, `jpeg`, `png`, `webp` by default).
- **ntfy alerts** alongside email; email is now multipart (plain text + HTML).
- After signing in you return to the folder or video you originally requested.
- Configurable signed-URL lifetime (default 15 minutes, was 1 hour).
- B2 native API **v4**; account token cached in a 0600 file shared by all sessions.
- PHPUnit suite, an end-to-end suite against a mock B2 server, and GitHub Actions CI on PHP 8.1–8.4.

### Changed
- Requires **PHP 8.1+** (was 7.4, which has been end-of-life since 2022).
- Code split into `public/` (web root: `index.php` + assets), `src/`, `views/` and `bin/`; all settings in one `config.php` outside the web root.
- Removed: `?stream=`, HTTP Basic Auth, the cdnjs dependency, `USE_SESSION_CACHE`, `RATE_LIMIT_FILE` (now under `storage_dir`).

## v1.5.1
- **Permissions-Policy header** added, explicitly denying all browser features except those required by the video player (`fullscreen`, `picture-in-picture`, `autoplay`, `encrypted-media` restricted to same origin)
- Achieves **A+** rating on [securityheaders.com](https://securityheaders.com)

## v1.5.0
- **Secrets outside the document root** — credentials (`B2_KEY_ID`, `B2_APPLICATION_KEY`, `B2_BUCKET_NAME`, `B2_BUCKET_ID`, `AUTH_USERNAME`, `AUTH_PASSWORD`, `MFA_SECRET`) are now loaded from a separate `b2browse-secrets.php` file that lives outside the webroot
- `defined()…||…define()` fallback pattern in `b2browse.php` ensures safe defaults if a constant is absent from the secrets file
- `require_once __DIR__ . '/../private/b2browse-secrets.php'` loads the secrets file at the top of the script, before any credential is referenced
- Installation instructions updated to reflect the new two-file deployment model

## v1.4.0
- **Content Security Policy** - Strict nonce-based CSP; no `unsafe-inline` in `script-src` or `style-src`
- All inline CSS extracted to external `b2-browse.css`
- All inline event handlers (`onclick`, `onchange`, `onkeyup`, `onerror`) replaced with `addEventListener` in nonced script blocks
- Per-request cryptographic nonce generated via `random_bytes(16)`
- Added CSP directives: `form-action`, `base-uri`, `frame-ancestors`, `object-src`, `img-src`, `media-src`
- Added `X-Content-Type-Options: nosniff` header globally
- Added `Referrer-Policy: no-referrer` header

## v1.3.1
- TOTP replay store moved from `$_SESSION` (per-session) to a shared server-side file with `flock(LOCK_EX)`, blocking replay across all sessions
- QR code library switched from jsDelivr `qrcode` to cdnjs `qrcodejs` (more reliable, constructor API)
- Visible fallback message when QR code CDN is unreachable

## v1.3.0
- **Security audit and hardening release** with 11 fixes:
  - IP spoofing: `getClientIP()` uses only `REMOTE_ADDR`
  - Session fixation: `session_regenerate_id(true)` on every login
  - TOTP replay: used time-slices tracked and rejected
  - MFA secret privacy: QR code rendered client-side (secret never sent to third-party servers)
  - Path traversal: `sanitizeAndValidatePath()` with `B2_ALLOWED_PREFIX` enforcement
  - Race condition: rate-limit file uses `flock(LOCK_EX)` for atomic read-modify-write
  - Predictable rate-limit file: SHA-256-derived filename (or explicit `RATE_LIMIT_FILE`)
  - Timing attacks: `hash_equals()` for all credential comparisons
  - CSRF protection: session-bound token on MFA form
  - `session_write_close()` ordering: `last_activity` updated before session release
  - First-time stream 403: `?stream=` accepts valid Basic Auth when no session exists

## v1.2.0
- Added video playback with HTML5 player
- HTTP Range support for video seeking
- Keyboard shortcuts for video control
- Activity logging for playback events
- Professional email alert design
- Fixed SPF alignment for email delivery
- Email alerts to multiple addresses

## v1.1.0
- Added Multi-Factor Authentication (TOTP)
- Added fail2ban-style rate limiting
- Activity logging system
- Email alerts for security events
- Secure logout functionality
- Enhanced security measures

## v1.0.0
- Initial release
- Folder navigation
- Byte-level streaming downloads
- HTTP Basic Authentication
- Search and filter
- Mobile responsive design
