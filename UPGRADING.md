# Upgrading from v1.x to v2.0

v2.0 changes the file layout, the configuration format and the sign-in method.
Plan on about 15 minutes. Your footage, bucket and B2 layout are untouched.

## 1. Create a restricted B2 key (if you have not already)

v2 refuses to start with a key that can write or delete, or that is not
limited to one bucket and a name prefix. Create one as described in
[README → Create a restricted B2 application key](README.md#2-create-a-restricted-b2-application-key).
To keep using a broader key temporarily, set `'enforce_key_scope' => false`;
the app logs a `KEY_SCOPE_WARNING` instead.

## 2. Deploy the new files

```
/home/you/b2-browser/           ← new application directory (NOT web-accessible)
├── config.php                  ← you create this in step 3
├── src/  views/  bin/
└── public/                     ← contents go in your web root
    ├── index.php
    └── assets/  (b2-browse.css, app.js, qrcode-generator.js)
```

Remove `b2browse.php` / `b2-browser.php` and `b2browse.css` / `b2-browse.css`
from the web root, then copy in the contents of `public/`. If the application
directory is not the parent of the directory holding `index.php`, set
`B2B_APP_ROOT` and `B2B_CONFIG` (see the top of `public/index.php`).

Bookmarks to `b2browse.php?path=…` keep working if you name the new entry
file `b2browse.php` instead of `index.php`; the app uses only relative links.

## 3. Convert the configuration

Copy `config.sample.php` to `config.php` (outside the web root, `chmod 400`)
and move your values across:

| v1.x (`b2browse-secrets.php` / script constants) | v2 `config.php` key |
|---|---|
| `B2_KEY_ID` | `b2.key_id` |
| `B2_APPLICATION_KEY` | `b2.application_key` |
| `B2_BUCKET_NAME` | `b2.bucket_name` |
| `B2_BUCKET_ID` | `b2.bucket_id` |
| `B2_ALLOWED_PREFIX` | `b2.prefix` (now required to match the key's `namePrefix`) |
| `FILES_PER_PAGE` | `b2.files_per_page` |
| `AUTH_USERNAME` | `auth.username` |
| `AUTH_PASSWORD` (plaintext) | `auth.password_hash` — run `php bin/hash-password.php` |
| `MFA_ENABLED` + `MFA_SECRET` | `auth.totp_secret` (empty = MFA off) |
| `MFA_ISSUER` | `auth.totp_issuer` |
| `SESSION_TIMEOUT` | `session.idle_timeout` (plus new `session.absolute_timeout`) |
| `RATE_LIMIT_ENABLED` | always on |
| `MAX_LOGIN_ATTEMPTS`, `ATTEMPT_WINDOW`, `LOCKOUT_DURATION` | `rate_limit.max_attempts`, `.window`, `.lockout_duration` |
| `RATE_LIMIT_FILE` | `storage_dir` (holds all state files) |
| `LOG_ENABLED`, `LOG_FILE_PATH` | `log.enabled`, `log.path` |
| `EMAIL_ALERTS_ENABLED`, `ALERT_EMAILS`, `ADMIN_EMAIL`, `YOUR_DOMAIN` | `alerts.email.enabled`, `.recipients` (array), `.reply_to`, `.from` |
| `ALERT_ON_LOGIN` … `ALERT_ON_PLAYBACK` | `alerts.on_login` … `alerts.on_playback` |
| `YOUR_HOMEPAGE` | `site.homepage` |
| `DEBUG_MODE` | `debug` |
| `USE_SESSION_CACHE` | removed (token cached in `storage_dir`) |

Your existing TOTP secret keeps working, so authenticator apps need no
re-enrolment. If it is shorter than 32 characters, consider generating a new
160-bit one with `php bin/totp-setup.php <username>`.

## 4. Create the storage directory

```bash
mkdir -p ~/b2-browser-data && chmod 700 ~/b2-browser-data
```

It must be writable by the PHP user. The app reports a configuration error
if it is missing.

## 5. Check

Open the site. You should see a sign-in form instead of the browser's Basic
Auth prompt. Sign in, then confirm that the activity log shows `LOGIN_SUCCESS`.

## Behaviour changes to be aware of

- **No `?stream=` URL.** Players use short-lived signed B2 URLs (`media.signed_url_ttl`, default 15 minutes, was 1 hour). For very long clips, raise the TTL or download instead.
- **Sign-out is POST.** The old `?logout=1` link does nothing now.
- **Empty credentials no longer mean "public".** Set `auth.allow_no_auth` only if something in front of the app (e.g. Authelia or oauth2-proxy) authenticates.
- **Behind a reverse proxy**, configure it to rewrite `REMOTE_ADDR` (nginx `real_ip`, Apache `mod_remoteip`); otherwise every client shares the proxy's address and one bad actor locks everyone out.
- **PHP 8.1+** is required.
