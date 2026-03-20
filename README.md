# Backblaze B2 Camera Backup Browser

A secure, feature-rich web interface for browsing and streaming camera footage stored in Backblaze B2 private buckets. Perfect for home security systems, surveillance cameras, or any scenario requiring secure remote access to video backups.

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Security](https://img.shields.io/badge/security-CSP%20%2B%20MFA%20%2B%20Rate%20Limiting-brightgreen)
![Security Headers](https://img.shields.io/badge/securityheaders.com-A%2B-brightgreen)
![Secrets](https://img.shields.io/badge/secrets-outside%20webroot-important)

## Features

### Security & Authentication
- **Content Security Policy (CSP)** - Strict per-request nonce-based policy; no `unsafe-inline`
- **Permissions Policy** - Browser feature access explicitly denied except where required by the video player
- **HTTP Basic Authentication** - Password-protected access
- **Multi-Factor Authentication (TOTP)** - Google Authenticator, Authy, etc.
- **CSRF Protection** - Session-bound tokens on all forms
- **Rate Limiting** - Fail2ban-style brute force protection with file-level locking
- **TOTP Replay Prevention** - Cross-session global tracking of used time-slices
- **Timing-Safe Comparisons** - `hash_equals()` on all credential checks
- **Session Fixation Protection** - Session ID regenerated on login
- **Path Traversal Protection** - Strict input validation with optional prefix restriction
- **Activity Logging** - Comprehensive JSON audit trail with automatic rotation
- **Email Alerts** - Real-time HTML security notifications
- **Secure Logout** - Clean session termination

### Media Viewing
- **Video Playback** - Stream MP4s directly in browser with full seeking support
- **iOS Compatibility** - Signed B2 URLs for Safari; VLC deep-link for iOS devices
- **Player Controls** - Play, pause, seek, volume, fullscreen
- **Keyboard Shortcuts** - Space to play/pause, arrows to seek, F for fullscreen
- **Image Download** - JPG file support
- **Mobile Responsive** - Works seamlessly on phones and tablets

### File Management
- **Folder Navigation** - Browse by year/month/day/camera structure
- **Search & Filter** - Find files by name or type
- **File Metadata** - Size, date, camera ID display
- **Breadcrumb Navigation** - Easy path traversal
- **Pagination** - Handles large directories efficiently

### Performance & Reliability
- **Streaming Downloads** - No memory limits, handles files of any size
- **Session Caching** - Reduced API calls to B2
- **Auto-reconnect** - Handles token expiration automatically
- **Optimized Listing** - Pagination for large directories

## Requirements

- PHP 7.4 or higher
- PHP extensions: `curl`, `json`
- Backblaze B2 account with a configured bucket
- Web server (Apache/Nginx) or shared hosting
- HTTPS/SSL certificate (required for security headers to be effective)

## Installation

### 1. Clone Repository

```bash
git clone https://github.com/alan-berger/b2-browser.git
cd b2-browser
```

### 2. Create Backblaze B2 Application Key

1. Log in to [Backblaze B2](https://www.backblaze.com/b2/cloud-storage.html)
2. Navigate to **App Keys** → **Add a New Application Key**
3. Configure the key:
   - **Name**: "Camera Browser - Read Only"
   - **Access**: Select your specific bucket
   - **Permissions**: **Read Only**
   - **Allow List All Bucket Names**: Check this box (required)
4. Save the **Application Key ID** and **Application Key**
5. Note your **Bucket Name** and **Bucket ID** (found in bucket details)

**Minimum Required Permissions:**
- `listFiles` - Browse bucket contents
- `readFiles` - Download/stream files
- `listBuckets` or `listAllBucketNames` - Required for bucket-specific keys

### 3. Create the Secrets File

All sensitive credentials live in a separate file — `b2browse-secrets.php` — that must be placed **outside your document root**. This means that even if your web server is misconfigured and accidentally serves PHP files as plain text, your credentials are never exposed.

**Typical directory layout:**

```
/home/username/
├── private/
│   └── b2browse-secrets.php   ← secrets file lives here (not web-accessible)
└── public_html/               ← your document root
    ├── b2browse.php
    └── b2browse.css
```

On cPanel-based shared hosting the structure is usually:

```
/home/username/
├── private/
│   └── b2browse-secrets.php
└── public_html/
    ├── b2browse.php
    └── b2browse.css
```

The `require_once` at the top of `b2browse.php` loads this file automatically:

```php
require_once __DIR__ . '/../private/b2browse-secrets.php';
```

**Create the `private/` directory and the secrets file:**

```bash
mkdir -p ~/private
chmod 500 ~/private
touch ~/private/b2browse-secrets.php
chmod 400 ~/private/b2browse-secrets.php
```

`400` (read-only, owner only) is stricter than the conventional `600` — PHP only ever reads this file, so write access is unnecessary. If you need to edit it in future, temporarily unlock it, make your changes, then lock it down again:

```bash
chmod 600 ~/private/b2browse-secrets.php
# edit the file
chmod 400 ~/private/b2browse-secrets.php
```

**Populate `b2browse-secrets.php` with your actual credentials:**

```php
<?php

define('B2_KEY_ID',          'your_application_key_id');
define('B2_APPLICATION_KEY', 'your_application_key');
define('B2_BUCKET_NAME',     'your_bucket_name');
define('B2_BUCKET_ID',       'your_bucket_id');
define('AUTH_USERNAME',      'admin');
define('AUTH_PASSWORD',      'your_secure_password');
define('MFA_SECRET',         '');   // fill in after generating — see MFA setup below
```

> **Do not commit this file to version control.** Add `private/b2browse-secrets.php` to your `.gitignore`. The application's `b2browse.php` contains only safe placeholder defaults — all real secrets belong exclusively in this file.

### 4. Configure the Script

The non-sensitive configuration options remain in `b2browse.php`. Edit these to match your environment:

```php
// Optional: restrict browsing to a specific path prefix (e.g. 'camera-footage/')
// Leave empty to allow access to any file in the bucket
define('B2_ALLOWED_PREFIX', 'camera-footage');

// Your Domain and Contact Information
define('YOUR_DOMAIN',   'yourdomain.com');
define('YOUR_HOMEPAGE', 'https://yourdomain.com');
define('ADMIN_EMAIL',   'admin@yourdomain.com');
define('ALERT_EMAILS',  'admin@yourdomain.com,backup@yourdomain.com');

// Rate Limiting
define('RATE_LIMIT_ENABLED',  true);
define('MAX_LOGIN_ATTEMPTS',  5);
define('LOCKOUT_DURATION',    900);  // 15 minutes
define('ATTEMPT_WINDOW',      300);  // 5 minutes
define('RATE_LIMIT_FILE', '/home/username/temp/b2_login_attempts.json');

// Activity Logging and Email Alerts
define('LOG_ENABLED',          true);
define('LOG_FILE_PATH',        '/home/username/logs/b2_activity.log');
define('EMAIL_ALERTS_ENABLED', true);

// MFA toggle (secret itself lives in b2browse-secrets.php)
define('MFA_ENABLED', false);   // set to true after completing MFA setup below
```

### 5. Set Up Activity Logging

```bash
mkdir -p /home/username/logs
chmod 700 /home/username/logs
touch /home/username/logs/b2_activity.log
chmod 600 /home/username/logs/b2_activity.log
```

Update the path in `b2browse.php`:
```php
define('LOG_FILE_PATH', '/home/username/logs/b2_activity.log');
```

### 6. Upload to Server

Upload the following files to your web server:

| File | Destination | Notes |
|------|-------------|-------|
| `b2browse.php` | Document root (e.g. `public_html/`) | The main application |
| `b2browse.css` | Document root (e.g. `public_html/`) | Required by the CSP — must be in the same directory |
| `b2browse-secrets.php` | **Outside** document root (e.g. `private/`) | Must **not** be web-accessible |

> **Critical:** If `b2browse-secrets.php` is placed inside the document root, a misconfigured server could expose your B2 credentials, password, and MFA secret as plain text. Always verify the `private/` directory is not reachable via a browser before going live.

### 7. Access

Navigate to `https://yourdomain.com/b2browse.php`

## Security Headers

The application enforces a strict set of HTTP security headers on every response, including error and authentication pages. This configuration achieves an **A+** rating on [securityheaders.com](https://securityheaders.com).

### Content Security Policy

All inline styles have been extracted to `b2browse.css`, and all inline scripts use per-request cryptographic nonces — eliminating the need for `unsafe-inline` in `script-src`.

**CSP directives enforced:**

| Directive | Value | Purpose |
|-----------|-------|---------|
| `default-src` | `'self'` | Baseline: same-origin only |
| `script-src` | `'nonce-...'` | Only nonced scripts execute; XSS injection blocked |
| `style-src` | `'self'` | External stylesheet only; no inline styles |
| `img-src` | `'self' data:` | Same-origin images + data: URIs (QR code rendering) |
| `media-src` | `'self' https://*.backblazeb2.com` | Video streaming from signed B2 URLs |
| `form-action` | `'self'` | Forms can only submit to same origin |
| `base-uri` | `'self'` | Prevents `<base>` tag injection |
| `frame-ancestors` | `'none'` | Blocks framing (clickjacking protection) |
| `object-src` | `'none'` | Blocks plugin embeds (Flash, Java, etc.) |

### Permissions Policy

Browser features are explicitly denied except for the subset required by the video player:

| Feature | Value | Reason |
|---------|-------|--------|
| `fullscreen` | `(self)` | Required for video fullscreen button |
| `picture-in-picture` | `(self)` | Required for browser PiP control |
| `autoplay` | `(self)` | Required for video playback |
| `encrypted-media` | `(self)` | Required for EME negotiation on video streams |
| All others | `()` | Explicitly denied (camera, geolocation, microphone, payment, USB, etc.) |

### Additional Headers

`X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`

## Security Setup

### Enable Multi-Factor Authentication (Recommended)

#### Step 1: Generate MFA Secret

While `MFA_ENABLED` is set to `false` in `b2browse.php`, add these temporary lines at the **top** of `b2browse.php` (after the `require_once` line):

```php
// TEMPORARY: Generate MFA Secret — remove after copying the output
function tempGenerateMFASecret($length = 16) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $secret;
}
echo "Your MFA Secret: " . tempGenerateMFASecret();
exit;
```

Access the page, copy the generated secret, then **remove these lines** before continuing.

#### Step 2: Configure MFA

Copy the generated secret into `b2browse-secrets.php` (outside the document root):

```php
define('MFA_SECRET', 'YOUR_GENERATED_SECRET');
```

Then enable MFA in `b2browse.php`:

```php
define('MFA_ENABLED', true);
```

> The MFA secret is a sensitive credential — it belongs in `b2browse-secrets.php` alongside your other secrets, never hardcoded in `b2browse.php` directly.

#### Step 3: Set Up Authenticator App

1. Install Google Authenticator, Authy, or Microsoft Authenticator
2. Add the MFA secret that you generated to your authenticator app manually
3. Alternatively, log in and visit `?setup_mfa=1` to scan a QR code
4. **Save the MFA secret** securely as a backup in a password manager

**Compatible Apps:**
- Google Authenticator (iOS/Android)
- Microsoft Authenticator (iOS/Android)
- Authy (iOS/Android/Desktop)
- 1Password (with TOTP support)
- Bitwarden (with TOTP support)

### Configure Rate Limiting

Adjust security thresholds:

```php
define('RATE_LIMIT_ENABLED', true);
define('MAX_LOGIN_ATTEMPTS', 5);      // Failed attempts before lockout
define('LOCKOUT_DURATION', 900);      // 15 minutes
define('ATTEMPT_WINDOW', 300);        // 5 minutes
```

For the rate-limit data file, you can optionally specify an explicit path outside the webroot:
```php
define('RATE_LIMIT_FILE', '/var/lib/b2-browse/rate_limit.json');
```
If left empty, a SHA-256-derived filename is auto-generated in the system temp directory.

**Security Levels:**

- **High Security**: 3 attempts, 30-minute lockout
- **Balanced** (default): 5 attempts, 15-minute lockout
- **Relaxed**: 10 attempts, 10-minute lockout

### Email Alert Configuration

```php
define('LOG_ENABLED', true);
define('EMAIL_ALERTS_ENABLED', true);
define('ALERT_ON_LOGIN', true);           // Recommended
define('ALERT_ON_FAILED_LOGIN', true);    // Recommended
define('ALERT_ON_LOCKOUT', true);         // Recommended
define('ALERT_ON_DOWNLOADS', false);      // Optional (can be noisy)
define('ALERT_ON_PLAYBACK', false);       // Optional (can be noisy)
```

**Email Setup Requirements:**
1. Your server must support PHP `mail()` function
2. Configure SPF record for your domain (prevents spam folder)
3. Use HTTPS to protect credentials

**SPF Record Example:**
```
v=spf1 ip4:YOUR_SERVER_IP include:_spf.yourhost.com -all
```

## Usage

### Browsing Files

1. Log in with username and password
2. Enter MFA code (if enabled)
3. Navigate folders by clicking on them
4. Use breadcrumb navigation to go back

### Playing Videos

1. Browse to an MP4 file
2. Click **"Play"** button
3. Video opens in full-screen player
4. Use player controls or keyboard shortcuts:
   - `Space` or `K` - Play/Pause
   - `←` - Skip back 5 seconds
   - `→` - Skip forward 5 seconds
   - `F` - Fullscreen
   - `M` - Mute/Unmute

### Downloading Files

Click **"Download"** next to any file to download it.

### Filtering Files

When viewing folders with files:
- Use dropdown to filter by type (All/Videos/Images/Folders)
- Use search box to find files by name
- Filtering happens instantly without page reload

### Logging Out

Click **"Logout"** in the header. You'll be redirected to your homepage after 2 seconds.

## Folder Structure

The browser is designed to work with hierarchical folder structures. Example for camera systems:

```
camera-backups/
├── 2026/
│   ├── 01/
│   │   ├── 15/
│   │   │   ├── 000001/  (Camera 1)
│   │   │   │   ├── 10-30-00.mp4
│   │   │   │   ├── 10-30-00.jpg
│   │   │   │   ├── 10-45-00.mp4
│   │   │   │   └── 10-45-00.jpg
│   │   │   └── 000002/  (Camera 2)
│   │   │       ├── 10-30-00.mp4
│   │   │       └── 10-30-00.jpg
```

**Supports any folder structure** - the example above is just one common pattern.

## ⚙️ Configuration Reference

| Option | Default | Description |
|--------|---------|-------------|
| `B2_KEY_ID` | Required | Backblaze B2 Application Key ID |
| `B2_APPLICATION_KEY` | Required | Backblaze B2 Application Key |
| `B2_BUCKET_NAME` | Required | B2 bucket name |
| `B2_BUCKET_ID` | Required | B2 bucket ID |
| `B2_ALLOWED_PREFIX` | `''` (unrestricted) | Restrict browsing/downloads to files under this path prefix |
| `YOUR_DOMAIN` | Required | Domain name (used in email From address and footer) |
| `YOUR_HOMEPAGE` | Required | Homepage URL (redirect target after logout) |
| `AUTH_USERNAME` | `''` (disabled) | HTTP Basic Auth username |
| `AUTH_PASSWORD` | `''` (disabled) | HTTP Basic Auth password |
| `RATE_LIMIT_ENABLED` | `true` | Enable brute force protection |
| `MAX_LOGIN_ATTEMPTS` | `5` | Failed attempts before lockout |
| `LOCKOUT_DURATION` | `900` (15 min) | IP lockout duration in seconds |
| `ATTEMPT_WINDOW` | `300` (5 min) | Time window for tracking attempts |
| `RATE_LIMIT_FILE` | `''` (auto) | Explicit path for rate-limit data; auto-generates SHA-256-derived name if empty |
| `MFA_ENABLED` | `false` | Enable Multi-Factor Authentication |
| `MFA_SECRET` | `''` | TOTP secret (base32-encoded; generate with helper script) |
| `MFA_ISSUER` | `'B2 File Browser'` | Issuer name shown in authenticator apps |
| `SESSION_TIMEOUT` | `1800` (30 min) | Session timeout in seconds |
| `LOG_ENABLED` | `true` | Enable activity logging |
| `LOG_FILE_PATH` | Required | Absolute path to log file |
| `EMAIL_ALERTS_ENABLED` | `true` | Enable email notifications |
| `ADMIN_EMAIL` | Required | Email alert Reply-To address |
| `ALERT_EMAILS` | Required | Comma-separated email addresses for alerts |
| `FILES_PER_PAGE` | `100` | Maximum files per directory listing page |
| `USE_SESSION_CACHE` | `true` | Cache B2 auth token in session (reduces API calls) |
| `DEBUG_MODE` | `false` | Enable detailed error logging to PHP error log |

## Troubleshooting

### Configuration Errors

**"B2_KEY_ID not configured"**
- Fill in all four B2 credentials in the configuration section

**"Authentication failed"**
- Verify application key credentials are correct
- Ensure key has `listFiles` and `readFiles` permissions
- Check "Allow List All Bucket Names" is enabled

### Connection Issues

**"Memory exhausted" (shouldn't happen with streaming)**
- Check PHP memory limit: `memory_limit = 256M` in `php.ini`

**Video won't play / "No video with supported format and MIME type found"**
- Ensure `b2-browse.css` is in the same directory as `b2-browse.php` (CSP requires it)
- Verify CSP `media-src` includes B2 download domains (it does by default)
- Ensure HTTPS is enabled (some browsers require it for CSP to work)
- Check browser console (F12) for any CSP violation reports
- Try downloading the file to verify it's a valid MP4

**Styles missing / page looks unstyled**
- Ensure `b2-browse.css` is deployed alongside `b2-browse.php` in the same directory
- Check browser console for CSP violations related to `style-src`

**Files not appearing**
- Enable `DEBUG_MODE` and check PHP error logs
- Verify bucket has files in expected paths
- Check application key has access to bucket/prefix

### Security Issues

**"Account Temporarily Locked"**
- Wait for lockout duration to expire
- The rate-limit file uses a SHA-256-derived filename in the temp directory (or at `RATE_LIMIT_FILE` if configured). You can delete it to reset all lockouts
- Adjust rate limiting constants if too strict

**MFA code not working**
- Ensure server time is accurate (use NTP)
- Verify secret was copied correctly
- Check phone time is set to automatic
- TOTP codes expire every 30 seconds
- Each code can only be used once (replay protection); wait for the next code

**Emails going to spam**
- Add SPF record to your domain DNS
- Use proper From address (alerts@yourdomain.com)
- Enable DKIM if available
- Ensure proper DMARC alignment

**Locked out of MFA**
1. Access the server via SSH/FTP
2. Edit `b2browse-secrets.php` (outside the document root)
3. Temporarily clear the secret: `define('MFA_SECRET', '');`
4. Also set `define('MFA_ENABLED', false);` in `b2browse.php`
5. Log in, generate and configure a new MFA secret, then re-enable MFA

**QR code not rendering on MFA setup page**
- The QR library is loaded from `cdnjs.cloudflare.com` and authorised by the CSP nonce. If it fails to load, the page automatically shows the manual-entry fallback with the secret code
- You can alternatively self-host `qrcode.min.js` in the same directory for a fully self-contained deployment

### Performance

**Slow file listing**
- Reduce `FILES_PER_PAGE` if folders have many files
- Enable session caching (`USE_SESSION_CACHE`)

**Video buffering issues**
- Check internet connection speed
- Try lower resolution videos if available
- B2 egress is fast; likely local network issue

## Backblaze B2 Costs

**Free Tier:**
- First 10GB storage: **Free**
- 3× your storage in egress per month: **Free**

**Example:** If you store 50GB of camera footage:
- Storage cost: $0.006/GB/month = **$0.30/month**
- Free egress: 150GB/month
- Beyond free tier: $0.01/GB

**For typical home camera use** (streaming occasionally in emergencies), you'll likely stay within the free egress allowance.

## Security Best Practices

### Must Do
- Place `b2browse-secrets.php` **outside the document root** (e.g. `~/private/`) — this is not optional
- Use read-only B2 application keys
- Enable HTTP Basic Authentication
- Use HTTPS/SSL certificate (CSP and nonces depend on a secure transport)
- Deploy both `b2browse.php` and `b2browse.css` to the document root (CSP blocks inline styles)
- Enable MFA for production use
- Keep `DEBUG_MODE` disabled in production
- Restrict file permissions: `600` for PHP/CSS files and the secrets file, `700` for directories
- Never commit `b2browse-secrets.php` to version control — add it to `.gitignore`

### Recommended
- Set `RATE_LIMIT_FILE` to an explicit path outside the webroot
- Set `B2_ALLOWED_PREFIX` to restrict browsing scope
- Enable email alerts for logins and lockouts
- Regular review of activity logs
- Use strong, unique passwords (16+ characters)
- Keep PHP and server software updated
- Whitelist IP addresses if possible (hosting firewall)

### Advanced
- Self-host `qrcode.min.js` to eliminate the cdnjs.cloudflare.com dependency
- Set up log monitoring/alerting (e.g. integrate with Grafana/Loki)
- Monitor the rate-limit data file for attack patterns
- Use a `Report-URI` or `report-to` CSP directive to collect violation reports
- Back up your MFA secret securely

## Activity Logging

Logs are stored in JSON format at `LOG_FILE_PATH`:

```json
{"timestamp":"2026-03-14 12:00:00","event":"LOGIN_SUCCESS","username":"admin","ip":"1.2.3.4","details":{"method":"HTTP Basic Auth + MFA"}}
{"timestamp":"2026-03-14 12:05:30","event":"VIDEO_PLAYBACK","username":"admin","ip":"1.2.3.4","details":{"filename":"camera-backups/2026/03/14/000005/12-00-00.mp4"}}
```

**Events logged:**
- `LOGIN_SUCCESS` - Successful authentication
- `LOGIN_FAILED` - Failed login attempt
- `MFA_FAILED` - Invalid MFA code (includes replay detection)
- `ACCOUNT_LOCKED` - Rate limit triggered
- `LOGOUT` - User logged out
- `FILE_DOWNLOAD` - File downloaded
- `VIDEO_PLAYBACK` - Video streamed

**Log rotation:** Automatic at 10MB, old logs renamed with timestamp.

## Browser Compatibility

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)
- Supports HTML5 video with seeking

## Contributing

Contributions welcome! Please feel free to submit issues or pull requests.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- Built for secure remote access to camera backup systems
- Designed with [Backblaze B2 Cloud Storage](https://www.backblaze.com/b2/cloud-storage.html)
- Optimized for home security and surveillance use cases

## Support

If you encounter issues:

1. Check the [Troubleshooting](#troubleshooting) section
2. Enable `DEBUG_MODE` and review PHP error logs
3. Check activity logs at `LOG_FILE_PATH`
4. Open browser developer tools (F12) and check for CSP violations in the console
5. Open an issue on GitHub with:
   - PHP version
   - Error messages (including any CSP violation text)
   - Steps to reproduce

## Changelog

### v1.5.1
- **Permissions-Policy header** added, explicitly denying all browser features except those required by the video player (`fullscreen`, `picture-in-picture`, `autoplay`, `encrypted-media` restricted to same origin)
- Achieves **A+** rating on [securityheaders.com](https://securityheaders.com)

### v1.5.0
- **Secrets outside the document root** — credentials (`B2_KEY_ID`, `B2_APPLICATION_KEY`, `B2_BUCKET_NAME`, `B2_BUCKET_ID`, `AUTH_USERNAME`, `AUTH_PASSWORD`, `MFA_SECRET`) are now loaded from a separate `b2browse-secrets.php` file that lives outside the webroot
- `defined()…||…define()` fallback pattern in `b2browse.php` ensures safe defaults if a constant is absent from the secrets file
- `require_once __DIR__ . '/../private/b2browse-secrets.php'` loads the secrets file at the top of the script, before any credential is referenced
- Installation instructions updated to reflect the new two-file deployment model

### v1.4.0
- **Content Security Policy** - Strict nonce-based CSP; no `unsafe-inline` in `script-src` or `style-src`
- All inline CSS extracted to external `b2-browse.css`
- All inline event handlers (`onclick`, `onchange`, `onkeyup`, `onerror`) replaced with `addEventListener` in nonced script blocks
- Per-request cryptographic nonce generated via `random_bytes(16)`
- Added CSP directives: `form-action`, `base-uri`, `frame-ancestors`, `object-src`, `img-src`, `media-src`
- Added `X-Content-Type-Options: nosniff` header globally
- Added `Referrer-Policy: no-referrer` header

### v1.3.1
- TOTP replay store moved from `$_SESSION` (per-session) to a shared server-side file with `flock(LOCK_EX)`, blocking replay across all sessions
- QR code library switched from jsDelivr `qrcode` to cdnjs `qrcodejs` (more reliable, constructor API)
- Visible fallback message when QR code CDN is unreachable

### v1.3.0
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

### v1.2.0
- Added video playback with HTML5 player
- HTTP Range support for video seeking
- Keyboard shortcuts for video control
- Activity logging for playback events
- Professional email alert design
- Fixed SPF alignment for email delivery
- Email alerts to multiple addresses

### v1.1.0
- Added Multi-Factor Authentication (TOTP)
- Added fail2ban-style rate limiting
- Activity logging system
- Email alerts for security events
- Secure logout functionality
- Enhanced security measures

### v1.0.0
- Initial release
- Folder navigation
- Byte-level streaming downloads
- HTTP Basic Authentication
- Search and filter
- Mobile responsive design

---

**Security Notice:**
- `b2browse-secrets.php` must live **outside the document root** — verify this before going live
- Never commit `b2browse-secrets.php` to version control
- Always use HTTPS in production
- Deploy both `b2browse.php` and `b2browse.css` to the same directory in the document root
- Enable MFA for sensitive camera footage
- Regularly review activity logs
- Keep your MFA secret backed up securely (e.g. in a password manager)

**Made with ❤️ for secure home camera systems**
