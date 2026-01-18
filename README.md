# Backblaze B2 File Browser
A lightweight, user-friendly PHP web interface for browsing and downloading files from private Backblaze B2 buckets. Perfect for camera backup systems, file archives, or any scenario where you need secure web access to B2 storage.

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)
![License](https://img.shields.io/badge/license-MIT-green)

## Features

- **Intuitive folder navigation** - Browse through your B2 bucket hierarchy
- **Streaming downloads** - Efficiently download files of any size without memory issues
- **Secure authentication** - Optional HTTP Basic Auth with session timeout
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
git clone https://github.com/yourusername/b2-file-browser.git
cd b2-file-browser
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

// Session timeout in seconds (default: 30 minutes)
define('SESSION_TIMEOUT', 1800);

// Diagnostic mode - set to false in production
define('DEBUG_MODE', false);
```

### 4. Upload to Your Server

Upload `b2-browser.php` to your web server via FTP, SFTP, or your hosting control panel.

### 5. Access the Browser

Navigate to `https://yourdomain.com/b2-browser.php` in your web browser.

## Configuration Options

| Option | Default | Description |
|--------|---------|-------------|
| `B2_KEY_ID` | Required | Your Backblaze B2 Application Key ID |
| `B2_APPLICATION_KEY` | Required | Your Backblaze B2 Application Key |
| `B2_BUCKET_NAME` | Required | Your B2 bucket name |
| `B2_BUCKET_ID` | Required | Your B2 bucket ID |
| `AUTH_USERNAME` | `''` (disabled) | HTTP Basic Auth username |
| `AUTH_PASSWORD` | `''` (disabled) | HTTP Basic Auth password |
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

1. **Use Read-Only Credentials**
   - Create a B2 application key with only `listFiles` and `readFiles` capabilities
   - Never use your master application key

2. **Enable HTTP Basic Authentication**
   - Set `AUTH_USERNAME` and `AUTH_PASSWORD` for an extra security layer
   - Use strong, unique passwords

3. **Use HTTPS**
   - Always serve the script over HTTPS to encrypt credentials in transit
   - Most shared hosting providers offer free SSL certificates

4. **Restrict File Access** (Optional)
   - Use the B2 "File Name Prefix" option when creating your app key
   - Limits access to specific folders within your bucket

5. **Keep Credentials Secure**
   - Never commit credentials to version control
   - Use environment variables or separate config files (not tracked by Git)
   - Restrict file permissions: `chmod 600 b2-browser.php`

6. **Disable Debug Mode in Production**
   - Set `DEBUG_MODE` to `false` to prevent exposing sensitive information

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
│   │   │   ├── 000005/  (Camera 1)
│   │   │   │   ├── 10-30-00.mp4
│   │   │   │   ├── 10-30-00.jpg
│   │   │   │   ├── 10-35-00.mp4
│   │   │   │   └── 10-35-00.jpg
│   │   │   └── 000006/  (Camera 2)
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

**⚠️ Security Notice:** Never expose B2 credentials in public repositories. Always use environment variables or external configuration files for sensitive data.
dd
