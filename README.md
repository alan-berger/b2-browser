# Backblaze B2 File Browser

A lightweight, user-friendly PHP web interface for browsing and downloading files from private Backblaze B2 buckets. Perfect for camera backup systems, file archives, or any scenario where you need secure web access to B2 storage.

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)
![License](https://img.shields.io/badge/license-MIT-green)

## Features

- **Intuitive folder navigation** - Browse through your B2 bucket hierarchy
- **Streaming downloads** - Efficiently download files of any size without memory issues
- **Secure authentication** - HTTP Basic Auth with optional Multi-Factor Authentication (TOTP)
- **Rate limiting** - Fail2ban-style protection against brute force attacks
- **Multi-Factor Authentication** - TOTP support (Google Authenticator, Authy, etc.)
- **Media support** - Specialized handling for videos (MP4) and images (JPG)
- **Client-side filtering** - Filter by file type and search by filename
- **Mobile responsive** - Works seamlessly on desktop and mobile devices
- **Performance optimized** - Session caching reduces API calls
- **Security hardened** - Protection against directory traversal and other attacks

## Use Cases

- Camera backup systems with RTSP feeds
- Video surveillance archive browsing
- Personal or business file storage access
- Media library management
- Backup verification and retrieval

## Requirements

- PHP 7.4 or higher
- PHP extensions: `curl`, `json`
- Backblaze B2 account with a configured bucket
- Shared hosting or web server (Apache/Nginx)

## Installation

### 1. Clone or Download

```bash
git clone https://github.com/alan-berger/b2-browser.git
cd b2-browser
```

Or download the `b2-browser.php` file directly.

### 2. Create Backblaze B2 Application Key

1. Log in to your [Backblaze B2 account](https://www.backblaze.com/b2/cloud-storage.html)
2. Navigate to **App Keys** under B2 Cloud Storage
3. Click **Add a New Application Key**
4. Configure the key:
   - **Name**: Give it a descriptive name (e.g., "PHP File Browser - Read Only")
   - **Type of Access**: Select "Allow access to Bucket(s)" and choose your bucket
   - **Permissions**: Select **"Read Only"**
   - **Allow List All Bucket Names**: Check this box (required)
   - **(Optional) File Name Prefix**: Restrict access to specific folders
5. Click **Create New Key** and save both the **Key ID** and **Application Key**

**Important:** You'll also need your **Bucket ID** (found in the bucket details page).

### 3. Configure the Script

Open `b2-browser.php` and update the configuration section:

```php
// ============================================
// CONFIGURATION - FILL THESE IN
// ============================================
define('B2_KEY_ID', 'your_application_key_id');
define('B2_APPLICATION_KEY', 'your_application_key');
define('B2_BUCKET_NAME', 'your_bucket_name');
define('B2_BUCKET_ID', 'your_bucket_id');

// Optional: Add HTTP Basic Authentication
define('AUTH_USERNAME', 'admin');  // Leave empty to disable
define('AUTH_PASSWORD', 'secure_password');  // Leave empty to disable

// Rate Limiting (fail2ban style protection)
define('RATE_LIMIT_ENABLED', true);
define('MAX_LOGIN_ATTEMPTS', 5);      // Maximum failed attempts
define('LOCKOUT_DURATION', 900);      // Lockout time in seconds (15 minutes)
define('ATTEMPT_WINDOW', 300);        // Time window to track attempts (5 minutes)

// Multi-Factor Authentication (TOTP) - Optional but highly recommended
define('MFA_ENABLED', false);         // Set to true to enable MFA
define('MFA_SECRET', '');             // Generate using instructions below
define('MFA_ISSUER', 'B2 File Browser');

// Session timeout in seconds (default: 30 minutes)
define('SESSION_TIMEOUT', 1800);

// Diagnostic mode - set to false in production
define('DEBUG_MODE', false);
```

### 4. Upload to Your Server

Upload `b2-browser.php` to your web server via FTP, SFTP, or your hosting control panel.

### 5. Access the Browser

Navigate to `https://yourdomain.com/b2-browser.php` in your web browser.

## Advanced Security Setup

### Enabling Multi-Factor Authentication (TOTP)

Multi-Factor Authentication adds an extra layer of security by requiring a time-based one-time password (TOTP) in addition to your username and password.

#### Step 1: Generate MFA Secret

Add these temporary lines at the **top** of `b2-browser.php` inside the php code block:

```php
// TEMPORARY: Generate MFA Secret
echo "Your MFA Secret: " . generateMFASecret() . "\n";
exit;
```

Access the script in your browser, copy the generated secret, then **remove or comment out** these lines.

#### Step 2: Configure MFA

Update your configuration:

```php
define('MFA_ENABLED', true);
define('MFA_SECRET', 'ABCD1234EFGH5678IJKL');  // Paste your generated secret
define('MFA_ISSUER', 'B2 File Browser');        // Name shown in authenticator app
```

#### Step 3: Set Up Your Authenticator App

1. Install an authenticator app on your phone:
   - **Google Authenticator** (iOS/Android)
   - **Microsoft Authenticator** (iOS/Android)
   - **Authy** (iOS/Android/Desktop)
   - **1Password** or **Bitwarden** (with TOTP support)

2. Log in to the file browser with your username/password

3. Click the **" View MFA Setup"** link in the header

4. Scan the QR code with your authenticator app OR manually enter the secret code

5. **Save the secret code** in a secure location as backup

#### Step 4: Login with MFA

From now on, logging in requires:
1. Username and password (HTTP Basic Auth)
2. 6-digit code from your authenticator app

**Important Notes:**
- Your server's time must be accurate (within 90 seconds) for TOTP to work
- Back up your MFA secret securely - if lost, you'll be locked out
- Test MFA in a private/incognito window before logging out completely
- The 6-digit code refreshes every 30 seconds

### Rate Limiting Configuration

The script includes fail2ban-style rate limiting to prevent brute force attacks.

#### How It Works

```
┌─────────────────────────────────────────┐
│ Failed Login Attempts (5 minute window) │
├─────────────────────────────────────────┤
│ Attempt 1-4: Allowed (tracked)          │
│ Attempt 5: Locked for 15 minutes        │
│ After 15min: Lockout expires            │
│ Successful: Counter resets              │
└─────────────────────────────────────────┘
```

#### Configuration Options

```php
define('RATE_LIMIT_ENABLED', true);   // Enable/disable rate limiting
define('MAX_LOGIN_ATTEMPTS', 5);      // Failed attempts before lockout
define('LOCKOUT_DURATION', 900);      // Lockout duration (15 min = 900 sec)
define('ATTEMPT_WINDOW', 300);        // Window to track attempts (5 min = 300 sec)
```

#### Recommended Settings by Security Level

**High Security (Strict):**
```php
define('MAX_LOGIN_ATTEMPTS', 3);
define('LOCKOUT_DURATION', 1800);     // 30 minutes
define('ATTEMPT_WINDOW', 300);        // 5 minutes
```

**Balanced (Default):**
```php
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900);      // 15 minutes
define('ATTEMPT_WINDOW', 300);        // 5 minutes
```

**Relaxed:**
```php
define('MAX_LOGIN_ATTEMPTS', 10);
define('LOCKOUT_DURATION', 600);      // 10 minutes
define('ATTEMPT_WINDOW', 600);        // 10 minutes
```

#### Features

- **IP-based tracking** - Works correctly behind proxies, CloudFlare, load balancers
- **Persistent storage** - Survives PHP restarts (stored in system temp directory)
- **Automatic cleanup** - Old attempts expire automatically
- **User feedback** - Shows remaining lockout time
- **Successful login reset** - Counter clears immediately on successful auth

#### Monitoring Failed Attempts

Failed login attempts are stored in: `/tmp/b2_login_attempts.json`

To view current lockouts (SSH/server access):
```bash
cat /tmp/b2_login_attempts.json | python -m json.tool
```

To manually clear a locked IP:
```php
// Add this temporarily to your script, access it, then remove
clearFailedAttempts('1.2.3.4');  // Replace with actual IP
```

## Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `B2_KEY_ID` | Required | Your Backblaze B2 Application Key ID |
| `B2_APPLICATION_KEY` | Required | Your Backblaze B2 Application Key |
| `B2_BUCKET_NAME` | Required | Your B2 bucket name |
| `B2_BUCKET_ID` | Required | Your B2 bucket ID |
| `AUTH_USERNAME` | `''` (disabled) | HTTP Basic Auth username |
| `AUTH_PASSWORD` | `''` (disabled) | HTTP Basic Auth password |
| `RATE_LIMIT_ENABLED` | `true` | Enable fail2ban-style rate limiting |
| `MAX_LOGIN_ATTEMPTS` | `5` | Failed attempts before lockout |
| `LOCKOUT_DURATION` | `900` (15 min) | IP lockout duration in seconds |
| `ATTEMPT_WINDOW` | `300` (5 min) | Time window for tracking attempts |
| `MFA_ENABLED` | `false` | Enable Multi-Factor Authentication |
| `MFA_SECRET` | `''` | TOTP secret (generate with script) |
| `MFA_ISSUER` | `'B2 File Browser'` | Name in authenticator app |
| `SESSION_TIMEOUT` | `1800` (30 min) | Session timeout in seconds |
| `DEBUG_MODE` | `false` | Enable detailed error logging |
| `FILES_PER_PAGE` | `100` | Maximum files to list per directory |
| `USE_SESSION_CACHE` | `true` | Cache B2 auth token in session |

### Recommended Timeout Values

- **High security**: 900 seconds (15 minutes)
- **Balanced**: 1800 seconds (30 minutes) - default
- **Convenient**: 3600 seconds (1 hour)
- **Relaxed**: 7200 seconds (2 hours)

## Security Best Practices

### Authentication & Access Control

1. **Use Read-Only Credentials**
   - Create a B2 application key with only `listFiles` and `readFiles` capabilities
   - Never use your master application key

2. **Enable HTTP Basic Authentication**
   - Set `AUTH_USERNAME` and `AUTH_PASSWORD` for an extra security layer
   - Use strong, unique passwords (minimum 16 characters recommended)

3. **Enable Multi-Factor Authentication** (Highly Recommended)
   - Adds TOTP-based 2FA protection
   - Prevents unauthorized access even if password is compromised
   - See [Advanced Security Setup](#advanced-security-setup) for configuration

4. **Enable Rate Limiting** (Enabled by Default)
   - Protects against brute force attacks
   - Automatically locks out IPs after failed attempts
   - Configurable thresholds and lockout duration

### Transport Security

5. **Use HTTPS**
   - Always serve the script over HTTPS to encrypt credentials in transit
   - Most shared hosting providers offer free SSL certificates (Let's Encrypt)
   - HTTP Basic Auth credentials are sent with every request

### Access Restrictions

6. **Restrict File Access** (Optional)
   - Use the B2 "File Name Prefix" option when creating your app key
   - Limits access to specific folders within your bucket
   - Example: `camera-backups/` to restrict to only that folder

7. **IP Whitelisting** (Advanced)
   - Configure your web server to only allow access from specific IPs
   - Apache: Use `.htaccess` with `Allow from` directives
   - Nginx: Use `allow` and `deny` directives

### Code Security

8. **Keep Credentials Secure**
   - Never commit credentials to version control
   - Use environment variables or separate config files (not tracked by Git)
   - Restrict file permissions: `chmod 600 b2-browser.php`

9. **Disable Debug Mode in Production**
   - Set `DEBUG_MODE` to `false` to prevent exposing sensitive information
   - Debug logs can contain API responses and system paths

10. **Regular Updates**
    - Keep your PHP version updated
    - Monitor this repository for security updates
    - Review your B2 application keys periodically

### Monitoring & Auditing

11. **Monitor Failed Login Attempts**
    - Check `/tmp/b2_login_attempts.json` for suspicious activity
    - Review PHP error logs regularly
    - Consider setting up log monitoring alerts

12. **Review B2 Bucket Logs** (Optional)
    - Enable B2 bucket logging for audit trail
    - Monitor download patterns for anomalies

### Security Checklist

Use this checklist to ensure your installation is secure:

- [ ] Using read-only B2 application key (not master key)
- [ ] HTTP Basic Authentication enabled with strong password
- [ ] Multi-Factor Authentication (TOTP) enabled
- [ ] Rate limiting enabled and configured
- [ ] HTTPS/SSL certificate configured
- [ ] Debug mode disabled in production
- [ ] File permissions set correctly (600 or 640)
- [ ] Credentials not committed to version control
- [ ] Server time synchronized (for MFA)
- [ ] PHP version up to date
- [ ] Regular backups of MFA secret stored securely

## Usage

### Browsing Files

1. Navigate to the script URL
2. (If enabled) Enter your authentication credentials
3. Browse folders by clicking on them
4. Use breadcrumb navigation to move back up the hierarchy

### Downloading Files

Click the **"Download"** button next to any file to download it. Large files are streamed efficiently to prevent memory issues.

### Filtering Files

When viewing a folder with files:
- Use the **file type dropdown** to filter by Videos, Images, or Folders
- Use the **search box** to find files by name
- Filtering happens instantly without page reload

## Folder Structure Example

The script is designed to work with any folder structure, but here's an example for camera backup systems:

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

## Troubleshooting

### "Configuration Error" Message

Make sure you've filled in all four required credentials:
- `B2_KEY_ID`
- `B2_APPLICATION_KEY`
- `B2_BUCKET_NAME`
- `B2_BUCKET_ID`

### "Authentication Failed" Error

- Verify your application key credentials are correct
- Ensure the key has the required permissions (`listFiles`, `readFiles`)
- Check that "Allow List All Bucket Names" is enabled if using a bucket-specific key

### "Memory Exhausted" When Downloading Large Files

This shouldn't happen with the streaming implementation, but if it does:
- Increase PHP memory limit in `php.ini`: `memory_limit = 256M`
- Check your PHP error logs for more details

### Files Not Appearing

- Enable `DEBUG_MODE` temporarily to see detailed logs
- Check your PHP error log for "B2:" prefixed messages
- Verify your bucket has files in the expected path
- Ensure your application key has access to the bucket/prefix

### Session Expires Too Quickly

Adjust `SESSION_TIMEOUT` to a higher value (in seconds):
```php
define('SESSION_TIMEOUT', 3600); // 1 hour
```

### "Account Temporarily Locked" Message

This is rate limiting protection. Wait for the lockout duration to expire, or:
- Check if someone is attempting to brute force your login
- Manually clear the lockout file: `/tmp/b2_login_attempts.json`
- Adjust rate limiting settings if too strict

### MFA Code Not Working

Common issues and solutions:
- **Time sync issue**: Ensure your server time is accurate (use NTP)
- **Wrong secret**: Verify you copied the MFA secret correctly
- **App time mismatch**: Check your phone's time is set to automatic
- **Code expired**: TOTP codes change every 30 seconds, enter quickly

To check server time:
```bash
date
```

To verify MFA secret (temporarily add to script):
```php
echo "Current TOTP code should be: " . getTOTP(base32Decode(MFA_SECRET), floor(time()/30));
```

### Locked Out of MFA

If you lose access to your authenticator app:

1. Access server via SSH/FTP
2. Edit `b2-browser.php`
3. Temporarily set: `define('MFA_ENABLED', false);`
4. Log in with username/password only
5. Re-enable MFA and set up a new secret
6. **Important**: Save backup codes this time!

### Rate Limit File Permissions

If rate limiting isn't working, check temp directory permissions:
```bash
ls -la /tmp/b2_login_attempts.json
chmod 666 /tmp/b2_login_attempts.json  # If needed
```

## Performance Tips

1. **Enable Session Caching** (default: enabled)
   - Reduces API calls by caching the B2 auth token
   - Token is valid for 23 hours

2. **Adjust Files Per Page**
   - If you have folders with many files, reduce `FILES_PER_PAGE`
   - Smaller values = faster page loads

3. **Use File Prefix Restriction**
   - When creating your B2 app key, specify a prefix
   - Reduces the scope of API queries

## Browser Compatibility

- Chrome/Edge (latest)
- Firefox (latest)
- Safari (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Contributing

Contributions are welcome! Please feel free to submit issues or pull requests.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Acknowledgments

- Built for use with [Backblaze B2 Cloud Storage](https://www.backblaze.com/b2/cloud-storage.html)
- Designed for camera backup systems and media archives

## Support

If you encounter any issues or have questions:
1. Check the [Troubleshooting](#troubleshooting) section
2. Enable `DEBUG_MODE` and check PHP error logs
3. Open an issue on GitHub with detailed information

## Changelog

### v1.1.0 (2026-01-15)
- Added Multi-Factor Authentication (TOTP) support
- Added fail2ban-style rate limiting protection
- Improved security with IP-based brute force protection
- Added MFA setup page with QR code generation
- Enhanced authentication flow with two-factor verification
- Added user feedback for lockout status and remaining time

### v1.0.0 (2026-01-15)
- Initial release
- Folder navigation and file browsing
- Streaming downloads for large files
- HTTP Basic Authentication with session timeout
- Client-side filtering and search
- Mobile responsive design
- Security hardening (directory traversal protection)
- Session caching for B2 auth tokens

---

**⚠️ Security Notice:** 
- Never expose B2 credentials in public repositories
- Always use environment variables or external configuration files for sensitive data
- Enable MFA for production deployments handling sensitive camera footage
- Regularly review failed login attempts in `/tmp/b2_login_attempts.json`
- Keep your MFA secret backed up in a secure location (password manager, encrypted vault)
