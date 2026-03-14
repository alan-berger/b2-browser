# Backblaze B2 Camera Backup Browser

A secure, feature-rich web interface for browsing and streaming camera footage stored in Backblaze B2 private buckets. Perfect for home security systems, surveillance cameras, or any scenario requiring secure remote access to video backups.

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)
![License](https://img.shields.io/badge/license-MIT-green)
![Security](https://img.shields.io/badge/security-MFA%20%2B%20Rate%20Limiting-brightgreen)

## Features

### Security & Authentication
- **HTTP Basic Authentication** - Password-protected access
- **Multi-Factor Authentication (TOTP)** - Google Authenticator, Authy, etc.
- **Rate Limiting** - Fail2ban-style brute force protection
- **Activity Logging** - Comprehensive audit trail
- **Email Alerts** - Real-time security notifications
- **Secure Logout** - Clean session termination

### Media Viewing
- **Video Playback** - Stream MP4s directly in browser with full seeking support
- **Player Controls** - Play, pause, seek, volume, fullscreen
- **Keyboard Shortcuts** - Space to play/pause, arrows to seek, F for fullscreen
- **Image Download** - JPG file support
- **Mobile Responsive** - Works seamlessly on phones and tablets

### File Management
- **Folder Navigation** - Browse by year/month/day/camera structure
- **Search & Filter** - Find files by name or type
- **File Metadata** - Size, date, camera ID display
- **Breadcrumb Navigation** - Easy path traversal

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
- HTTPS/SSL certificate (recommended for security)

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

### 3. Configure the Script

Edit `b2-browser.php` and update the configuration section:

```php
// B2 Credentials
define('B2_KEY_ID', 'your_application_key_id');
define('B2_APPLICATION_KEY', 'your_application_key');
define('B2_BUCKET_NAME', 'your_bucket_name');
define('B2_BUCKET_ID', 'your_bucket_id');

// Authentication
define('AUTH_USERNAME', 'admin');
define('AUTH_PASSWORD', 'your_secure_password');

// Email Alerts
define('YOUR_DOMAIN',   'yourdomain.com');
define('YOUR_HOMEPAGE', 'https://yourdomain.com');
define('ADMIN_EMAIL',   'admin@yourdomain.com');
define('ALERT_EMAILS',  'admin@yourdomain.com,backup@yourdomain.com');
```

### 4. Set Up Activity Logging

```bash
mkdir -p /path/to/logs
chmod 700 /path/to/logs
touch /path/to/logs/b2_activity.log
chmod 600 /path/to/logs/b2_activity.log
```

Update in the script:
```php
define('LOG_FILE_PATH', '/path/to/logs/b2_activity.log');
```

### 5. Upload to Server

Upload `b2-browser.php` to your web server via SFTP or hosting control panel.

### 6. Access

Navigate to `https://yourdomain.com/b2-browser.php`

## Security Setup

### Enable Multi-Factor Authentication (Recommended)

#### Step 1: Generate MFA Secret

While "MFA_ENABLED', false", add these temporary lines at the **top** of `b2-browser.php` (after `<?php`):

```php
// TEMPORARY: Generate MFA Secret
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

Access the page, copy the secret, then **remove these lines** and set "MFA_ENABLED', true".

#### Step 2: Configure MFA

```php
define('MFA_ENABLED', true);
define('MFA_SECRET', 'YOUR_GENERATED_SECRET');
```

#### Step 3: Set Up Authenticator App

1. Install Google Authenticator, Authy, or Microsoft Authenticator
2. Add the MFA secret that you generated to your Authenticator app manually
5. **Save the MFA secret** securely as a backup in a password manager for safe keeping

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
│   │   │   │   ├── 10-35-00.mp4
│   │   │   │   └── 10-35-00.jpg
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
| `AUTH_USERNAME` | `''` (disabled) | HTTP Basic Auth username |
| `AUTH_PASSWORD` | `''` (disabled) | HTTP Basic Auth password |
| `RATE_LIMIT_ENABLED` | `true` | Enable brute force protection |
| `MAX_LOGIN_ATTEMPTS` | `5` | Failed attempts before lockout |
| `LOCKOUT_DURATION` | `900` (15 min) | IP lockout duration in seconds |
| `ATTEMPT_WINDOW` | `300` (5 min) | Time window for tracking attempts |
| `MFA_ENABLED` | `false` | Enable Multi-Factor Authentication |
| `MFA_SECRET` | `''` | TOTP secret (generate with script) |
| `SESSION_TIMEOUT` | `1800` (30 min) | Session timeout in seconds |
| `LOG_ENABLED` | `true` | Enable activity logging |
| `LOG_FILE_PATH` | Required | Path to log file |
| `EMAIL_ALERTS_ENABLED` | `true` | Enable email notifications |
| `ADMIN_EMAIL` | Required | Email alert Reply-To address |
| `ALERT_EMAILS` | Required | Comma-separated email addresses |
| `FILES_PER_PAGE` | `100` | Maximum files per directory, increase as needed |
| `USE_SESSION_CACHE` | `true` | Cache B2 auth token in session |
| `DEBUG_MODE` | `false` | Enable detailed error logging |

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

**Video won't play**
- Ensure HTTPS is enabled (some browsers require it)
- Check browser console for errors
- Try downloading the file to verify it's a valid MP4

**Files not appearing**
- Enable `DEBUG_MODE` and check PHP error logs
- Verify bucket has files in expected paths
- Check application key has access to bucket/prefix

### Security Issues

**"Account Temporarily Locked"**
- Wait for lockout duration to expire
- Check `/tmp/b2_login_attempts.json` for details
- Adjust rate limiting if too strict

**MFA code not working**
- Ensure server time is accurate (use NTP)
- Verify secret was copied correctly
- Check phone time is set to automatic
- TOTP codes expire every 30 seconds

**Emails going to spam**
- Add SPF record to your domain DNS
- Use proper From address (alerts@yourdomain.com)
- Enable DKIM if available
- Ensure proper DMARC alignment

**Locked out of MFA**
1. Access server via SSH/FTP
2. Edit the PHP file
3. Temporarily set: `define('MFA_ENABLED', false);`
4. Log in and set up new MFA secret
5. Re-enable MFA

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
- Use read-only B2 application keys
- Enable HTTP Basic Authentication
- Use HTTPS/SSL certificate
- Enable MFA for production use
- Keep `DEBUG_MODE` disabled in production
- Restrict file permissions (600/700)

### Recommended
- Enable email alerts for logins/lockouts
- Regular review of activity logs
- Use strong, unique passwords (16+ characters)
- Keep PHP and server software updated
- Whitelist IP addresses if possible (hosting firewall)

### Advanced
- Use B2 file name prefix restrictions
- Monitor `/tmp/b2_login_attempts.json` for attacks
- Set up log monitoring/alerting
- Backups MFA secret

## Activity Logging

Logs are stored in JSON format at `LOG_FILE_PATH`:

```json
{"timestamp":"2026-03-14 12:00:00","event":"LOGIN_SUCCESS","username":"admin","ip":"1.2.3.4","details":{"method":"HTTP Basic Auth + MFA"}}
{"timestamp":"2026-03-14 12:05:30","event":"VIDEO_PLAYBACK","username":"admin","ip":"1.2.3.4","details":{"filename":"camera-backups/2026/03/14/000005/12-00-00.mp4"}}
```

**Events logged:**
- `LOGIN_SUCCESS` - Successful authentication
- `LOGIN_FAILED` - Failed login attempt
- `MFA_FAILED` - Invalid MFA code
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
4. Open an issue on GitHub with:
   - PHP version
   - Error messages
   - Steps to reproduce

## Changelog

### v1.2.0 (2026-03-14)
- Added video playback with HTML5 player
- HTTP Range support for video seeking
- Keyboard shortcuts for video control
- Activity logging for playback events
- Professional email alert design
- Fixed SPF alignment for email delivery
- Email alerts to multiple addresses

### v1.1.0 (2026-03-13)
- Added Multi-Factor Authentication (TOTP)
- Added fail2ban-style rate limiting
- Activity logging system
- Email alerts for security events
- Secure logout functionality
- Enhanced security measures

### v1.0.0 (2026-01-15)
- Initial release
- Folder navigation
- Byte level streaming downloads
- HTTP Basic Authentication
- Search and filter
- Mobile responsive design

---

**Security Notice:** 
- Never commit credentials to version control
- Always use HTTPS in production
- Enable MFA for sensitive camera footage
- Regularly review activity logs
- Keep MFA secret backed up securely

**Made with ❤️ for secure home camera systems**
