# Backblaze B2 Camera Backup Browser

A secure web interface for browsing, playing and downloading camera footage stored in a private Backblaze B2 bucket. Built for home security and surveillance setups that need safe remote access to video backups, without exposing the bucket.

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Tests](https://github.com/alan-berger/b2-browser/actions/workflows/tests.yml/badge.svg)
![B2 API](https://img.shields.io/badge/B2%20API-v4-red)
![Security](https://img.shields.io/badge/security-CSP%20%2B%20MFA%20%2B%20Rate%20Limiting-brightgreen)
![Security Headers](https://img.shields.io/badge/securityheaders.com-A%2B-brightgreen)
![Secrets](https://img.shields.io/badge/secrets-outside%20webroot-important)

> **Upgrading from 1.x?** v2.0 changes the layout, configuration and sign-in method. See [UPGRADING.md](UPGRADING.md) and the [CHANGELOG](CHANGELOG.md).

## Features

### Security
- **Two layers of scope control** — the app confines every listing, playback and download to a configured prefix, and refuses to run unless the B2 key itself is read-only and restricted to that bucket and prefix
- **Form sign-in with TOTP MFA** — passwords stored as `password_hash()`, single-use codes enforced across all sessions, real sign-out
- **Brute-force protection** — failures at either step count towards a per-address lockout (IPv6 grouped by /64), with one alert per lockout
- **Strict Content Security Policy** — no inline script or style anywhere; images and video only from your account's B2 download host
- **Hardened sessions** — `Secure`/`HttpOnly`/`SameSite=Strict` cookies, strict mode, idle *and* absolute timeouts, ID rotation at login
- **CSRF protection** on every form, including sign-out
- **Short-lived signed URLs** for playback; footage is never made public
- **Fails closed** — missing credentials, unwritable state or an over-privileged key stop the app rather than weaken it
- **Alerts** by email and/or [ntfy](https://ntfy.sh) for logins, repeated failures and lockouts, plus a JSON-lines activity log

### Media
- In-browser video playback with seeking, keyboard shortcuts and an iOS **Open in VLC** link
- **Jump to date** picker for `YYYY/MM/DD` folder layouts
- **Image thumbnails**, authorised by one token per folder
- Streaming downloads of any size that don't block the session
- Filter by type and search by name; Previous/Next pagination
- Date and camera detected from the path; configurable video and image extensions
- Responsive layout for phones

## Requirements

- PHP **8.1 or newer** with `curl`, `json` and `mbstring`
- HTTPS (the session cookie is `Secure`)
- A Backblaze B2 private bucket
- Write access to a directory outside the web root (rate-limit, TOTP and token state)
- No Composer or database needed at runtime

## Installation

### 1. Get the code

```bash
git clone https://github.com/alan-berger/b2-browser.git ~/b2-browser
```

Only `public/` is web-facing. Everything else, including your config, stays outside the document root:

```
/home/you/b2-browser/         ← application (NOT web-accessible)
├── config.php                ← created in step 3
├── src/   views/   bin/
└── public/                   ← point the document root here,
    ├── index.php                or copy these into your existing web root
    └── assets/
```

If `public/` is not inside the application directory (typical on shared hosting, where the web root is fixed), copy its contents into the web root and set `B2B_APP_ROOT` / `B2B_CONFIG` via your web server (`SetEnv` / `fastcgi_param`), or edit the two lines at the top of `public/index.php`.

### 2. Create a restricted B2 application key

Never use your master key. Create a key that can only read one bucket under one prefix. With the [B2 CLI](https://www.backblaze.com/docs/cloud-storage-command-line-tools) (v4 syntax):

```bash
b2 key create --bucket my-camera-bucket --name-prefix camera-footage/ \
    b2-browser listFiles,readFiles,shareFiles
```

Or in the web UI: **App Keys → Add a New Application Key**, choose the bucket, set *Type of Access* to **Read Only**, and set *File name prefix* to the folder your cameras upload to (for example `camera-footage/`).

The app checks the key at sign-in. It refuses to run if the key lacks `listFiles`, `readFiles` or `shareFiles`, holds any `write*`/`delete*` capability, or isn't restricted to exactly your bucket and a prefix containing `b2.prefix`. The owner sees the reasons after signing in. B2 then enforces the same boundary server-side, so even an application bug cannot reach the rest of the bucket.

### 3. Configure

```bash
cd ~/b2-browser
cp config.sample.php config.php
php bin/hash-password.php                 # paste the output into auth.password_hash
php bin/totp-setup.php alan               # paste totp_secret; enrol your authenticator
chmod 400 config.php
```

`totp-setup.php` prints the secret, an `otpauth://` URI, and a `qrencode -t ansiutf8 …` command that shows a scannable QR code in your terminal. The secret is never shown on a web page unless you enable `auth.mfa_setup_page`. Check enrolment with `php bin/totp-setup.php --check <secret>`.

Fill in the four `b2.*` values and `b2.prefix`; everything else has defaults. The full list is in the [configuration reference](#configuration-reference).

### 4. Create the state and log directories

```bash
mkdir -p ~/b2-browser-data ~/logs
chmod 700 ~/b2-browser-data
```

Set `storage_dir` and `log.path` to match. Both must be writable by the PHP user.

### 5. Web server

Point the document root at `public/` (or copy its contents into an existing root). `src/`, `views/` and `bin/` include `.htaccess` deny rules as a safety net for Apache; on nginx, simply keep them out of the root.

**Behind a reverse proxy** (Cloudflare, Traefik, nginx…), make the web server rewrite `REMOTE_ADDR` from the trusted proxy's header (nginx `set_real_ip_from` + `real_ip_header`, Apache `mod_remoteip`). The app deliberately ignores `X-Forwarded-For`, because any client can forge it. Without the rewrite, every visitor shares the proxy's address and one attacker locks everyone out.

### 6. Sign in

Browse to the site and sign in with your username, password and a TOTP code. The activity log should show `LOGIN_SUCCESS`.

## Configuration reference

All settings live in `config.php`; see `config.sample.php` for comments.

| Key | Default | Description |
|---|---|---|
| `b2.key_id`, `b2.application_key` | — | **Required.** Restricted application key |
| `b2.bucket_name`, `b2.bucket_id` | — | **Required.** The bucket |
| `b2.prefix` | `''` | Only objects under this prefix are reachable; `/` appended automatically |
| `b2.enforce_key_scope` | `true` | Refuse to run with an over-privileged key |
| `b2.files_per_page` | `100` | Items per page (1–1000) |
| `auth.username`, `auth.password_hash` | — | **Required** unless `allow_no_auth` |
| `auth.totp_secret` | `''` | Base32 TOTP secret; empty disables MFA |
| `auth.totp_issuer` | `B2 File Browser` | Label shown in authenticator apps |
| `auth.mfa_setup_page` | `false` | Show the secret and QR code at `?mfa_setup` to signed-in users |
| `auth.allow_no_auth` | `false` | Serve without sign-in, only when something else authenticates |
| `session.idle_timeout` | `1800` | Seconds of inactivity before sign-out |
| `session.absolute_timeout` | `43200` | Maximum session age in seconds |
| `session.cookie_name` | `b2b_session` | Session cookie name |
| `session.secure_cookie` | `true` | `Secure` flag; disable only for local HTTP testing |
| `rate_limit.max_attempts` | `5` | Failures before lockout |
| `rate_limit.window` | `300` | Seconds over which failures are counted |
| `rate_limit.lockout_duration` | `900` | Seconds an address stays locked |
| `rate_limit.ipv6_prefix` | `64` | IPv6 grouping prefix length |
| `storage_dir` | — | **Required.** Writable directory for state files |
| `media.video_extensions` | `mp4 m4v mov webm` | Playable types |
| `media.image_extensions` | `jpg jpeg png webp` | Types shown as thumbnails |
| `media.signed_url_ttl` | `900` | Player link lifetime in seconds |
| `media.thumbnails`, `media.thumbnail_ttl` | `true`, `600` | Folder thumbnails and their token lifetime |
| `media.date_path_format` | `{Y}/{m}/{d}/` | Day-folder layout under the prefix; empty hides the date picker |
| `site.title`, `site.homepage` | | Page title; target of "Return to homepage" and sign-out |
| `site.hsts` | `true` | Send `Strict-Transport-Security` over HTTPS |
| `log.enabled`, `log.path`, `log.max_bytes` | `true`, —, 10 MB | Activity log and rotation size |
| `alerts.on_*` | login, failed login, lockout on | Which events alert |
| `alerts.email.*` | disabled | `from`, `reply_to`, `recipients` (array) |
| `alerts.ntfy.*` | disabled | `server`, `topic`, `token` (for protected topics) |
| `theme.stylesheets` | `[]` | Site stylesheets loaded before the app's (same-origin paths) |
| `theme.logo`, `theme.logo_alt`, `theme.heading`, `theme.heading_link` | empty | Branding shown at the top of every page |
| `theme.nav` | `[]` | Navigation links, `[['LABEL', '/path'], …]` |
| `theme.favicon` | empty | Favicon path |
| `theme.toggle`, `theme.storage_key` | `true`, `b2-theme` | Light/dark switch and its localStorage key |
| `theme.classes.{wrapper,header,nav,toggle}` | empty | Reproduce the host site's header/menu markup with these class names |
| `debug` | `false` | Show error details in the browser — never in production |

## Matching your site's look

b2-browser can wear the theme of the site it's installed on: your stylesheet, fonts, logo, heading and navigation, plus a light/dark toggle. Everything is optional; with no `theme` settings it uses a clean built-in look that still follows the visitor's light/dark preference.

```php
'theme' => [
    'stylesheets'  => ['/style.css', 'assets/theme.css'],
    'logo'         => '/images/logo.svg',
    'logo_alt'     => 'Example logo',
    'heading'      => 'example.org',
    'nav'          => [['HOME', '/'], ['ABOUT', '/about.php'], ['B2', '/b2/']],
    'favicon'      => '/favicon.ico',
    'storage_key'  => 'site-theme',   // same key as your site's toggle
],
```

- **Colours** come from your site's stylesheet through CSS custom properties. If it defines `--bg`, `--text`, `--text-muted`, `--border`, `--accent` and `--accent-hover` (switching them under `[data-theme="dark"]`), b2-browser uses them; anything missing falls back to the built-in palette.
- **Fonts, width and button text colour** go in a small `public/assets/theme.css` of your own — copy `theme.example.css`. It sets `--b2-font-body`, `--b2-font-heading`, `--b2-max-width` and `--b2-on-accent`. `theme.css` is git-ignored, so upgrades never overwrite it.
- **Identical header and menu.** Set `theme.classes` to the class names your site's own header uses (`wrapper`, `header`, `nav`, `toggle`) and b2-browser emits the same markup — logo and `<h1>` inside the header element, links and the theme button inside the nav — so your stylesheet positions them pixel-for-pixel as on every other page. Without it, b2-browser draws its own similar header.
- **Light/dark** is an attribute on `<html>` (`data-theme`), set before first paint by `theme-init.js` and switched by the toggle. With the same `storage_key` as your site, the visitor's choice carries across both.
- **Security is unchanged.** Stylesheets, fonts, the logo and the favicon must be served by your own site, because the CSP only allows `'self'` for those; the config rejects anything else, and navigation links must be paths or `http(s)` URLs. Your site's inline theme scripts aren't needed: b2-browser does the same job from its own external script.

## Security model

### What protects the footage

1. **The B2 key.** Read-only, one bucket, one prefix. B2 refuses anything else, whatever the app does.
2. **The prefix policy.** Every requested path is validated (UTF-8, no control characters, no empty or dot segments, ≤ 1024 bytes) and must sit under `b2.prefix`.
3. **Authentication.** Password then TOTP. Both steps count towards the lockout, and the counter clears only after both succeed. TOTP time steps are consumed atomically in a store shared by all sessions, so a code cannot be reused anywhere.
4. **Signed URLs.** Playback and thumbnails use B2 download tokens valid for minutes. B2 authorises tokens by name prefix: a token for `clip.mp4` also covers `clip.mp4.bak`, and a folder's thumbnail token covers every object in that folder. Keep the TTLs short.
5. **Downloads** are proxied through PHP with the account token, which never reaches the browser.

### Response headers

| Header | Value |
|---|---|
| `Content-Security-Policy` | `default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' <B2 download host>; media-src <B2 download host>; form-action 'self'; base-uri 'none'; frame-ancestors 'none'` |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` (over HTTPS) |
| `Permissions-Policy` | Everything denied except `autoplay`, `encrypted-media`, `fullscreen` and `picture-in-picture` for the same origin |
| `X-Frame-Options` / `X-Content-Type-Options` | `DENY` / `nosniff` |
| `Referrer-Policy` | `no-referrer` (keeps signed URLs out of referrers) |
| `Cross-Origin-Opener-Policy` / `-Resource-Policy` | `same-origin` |
| `Cache-Control` | `no-store` on pages; `private, no-store` and a sandbox CSP on downloads |

The download host in the CSP is the exact host B2 assigns your account (for example `https://f003.backblazeb2.com`), not a wildcard.

### Recommendations
- Keep MFA on. Store the TOTP secret in your password manager as a backup.
- Leave `auth.mfa_setup_page` off. Anyone with a session could otherwise copy your second factor.
- Use HTTPS only, and put the site behind your proxy's IP allow-list or a VPN if you can.
- Review the activity log, or ship it to Loki/Grafana; it is one JSON object per line.

## Usage

- **Browse:** click folders; the breadcrumb returns you to any level. *Home* is the configured prefix.
- **Jump to date:** pick a day to open its folder directly.
- **Play:** ▶️ opens the player. Keys: `space`/`k` play/pause, `f` fullscreen, `m` mute, `←`/`→` seek 5 s. On iOS, *Open in VLC* hands the signed URL to VLC.
- **Download:** ⬇️ streams the original file.
- **Filter/search:** narrows the current page by type or name.
- **Sign out:** top-right; this destroys the server-side session.

A link opened while signed out (a folder or video) is remembered and reopened after sign-in.

## Alerts and logging

**Alerts** go to email (multipart text + HTML, values escaped), ntfy (JSON publish; set `token` for protected topics), or both. Events: successful sign-in, the 3rd failure from an address, a lockout (once, when applied), the MFA page being viewed, and optionally every download or playback.

**Activity log** (`log.path`, JSON lines, rotated at `log.max_bytes`):

```json
{"timestamp":"2026-10-14T12:00:00+00:00","event":"LOGIN_SUCCESS","username":"alan","ip":"203.0.113.7","details":{"method":"password + TOTP","user_agent":"…"}}
```

| Event | Meaning |
|---|---|
| `LOGIN_SUCCESS`, `LOGOUT` | Session started / ended |
| `LOGIN_FAILED`, `MFA_FAILED` | Wrong credentials / wrong, expired or replayed code |
| `ADDRESS_LOCKED` | Lockout applied (logged once) |
| `CSRF_REJECTED` | Form submitted without a valid token |
| `FILE_DOWNLOAD`, `VIDEO_PLAYBACK` | File accessed |
| `MFA_SETUP_VIEWED` | TOTP secret displayed |
| `KEY_SCOPE_WARNING` | Over-privileged key in use with enforcement off |
| `PASSWORD_REHASH_RECOMMENDED` | PHP's default hash algorithm has changed; regenerate the hash |
| `ERROR` | Internal error (details also in the PHP error log) |

## Folder structure

Any layout works. The date picker and metadata assume day folders like this (adjust `media.date_path_format` otherwise):

```
camera-footage/
└── 2026/
    └── 10/
        └── 14/
            ├── 000001/          ← camera
            │   ├── 10-30-00.mp4
            │   └── 10-30-00.jpg
            └── 000002/
                └── 10-30-00.mp4
```

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Configuration error" page | Set `'debug' => true` briefly, or check the PHP error log, for the list of problems |
| "B2 application key is broader than allowed" | See step 2; the page lists what is wrong with the key |
| Sign-in always says "Your form expired" | Site served over plain HTTP while `session.secure_cookie` is on, so the browser drops the cookie; use HTTPS |
| Everyone gets locked out together | Reverse proxy without a `REMOTE_ADDR` rewrite (see step 5) |
| "Temporarily unavailable" | `storage_dir` missing or not writable |
| Video won't play but downloads fine | Codec unsupported by the browser (e.g. HEVC on Firefox); use VLC or download |
| Long video stops partway | Signed URL expired; raise `media.signed_url_ttl` |
| Thumbnails missing | `media.thumbnails` off, extension not in `media.image_extensions`, or a CSP violation in the browser console |

## Development

```bash
composer install
composer test          # PHPUnit unit tests
composer e2e           # end-to-end: mock B2 + the real app under PHP's built-in server
```

The end-to-end suite (`tests/e2e/`) starts a mock B2 v4 API and ntfy endpoint, then exercises sign-in, lockout, MFA replay, prefix confinement, pagination, thumbnails, downloads, token-expiry recovery, timeouts, key-scope enforcement and the security headers. It also fails on any PHP warning or deprecation. CI runs both suites on PHP 8.1–8.4.

## Backblaze B2 costs

B2 bills storage per GB-month and includes free egress up to a multiple of what you store. Occasional remote viewing of camera footage usually stays well within that. Check [current pricing](https://www.backblaze.com/cloud-storage/pricing). Each sign-in, listing, thumbnail folder and player page makes a few class B/C API calls; the account token is cached for 23 hours to keep that low.

## Browser compatibility

Current Chrome, Edge, Firefox and Safari, desktop and mobile. Playback depends on the browser supporting the video codec.

## Contributing

Issues and pull requests are welcome. Please run `composer test` and `composer e2e` before submitting. Report security issues privately as described in [SECURITY.md](SECURITY.md).

## License

MIT; see [LICENSE](LICENSE). `public/assets/qrcode-generator.js` is © Kazuhiko Arase, MIT-licensed.
