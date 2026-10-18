<?php
/**
 * b2-browser configuration.
 *
 * Copy this file to config.php OUTSIDE your web document root, then lock it
 * down (chmod 400). Every setting lives here, so upgrading the application
 * never overwrites your configuration.
 *
 * Only the settings marked REQUIRED must be filled in; everything else has a
 * sensible default and can be deleted from your copy if you are happy with it.
 */
return [

    // -----------------------------------------------------------------
    // Backblaze B2 (REQUIRED)
    // -----------------------------------------------------------------
    // Create a dedicated application key restricted to ONE bucket, a name
    // prefix, and only the listFiles, readFiles and shareFiles capabilities.
    // With 'enforce_key_scope' on (the default) the app refuses to run with
    // a broader key. See README → "Create a restricted B2 application key".
    'b2' => [
        'key_id'          => '',   // REQUIRED
        'application_key' => '',   // REQUIRED
        'bucket_name'     => '',   // REQUIRED
        'bucket_id'       => '',   // REQUIRED
        // Only objects under this prefix can be listed, played or downloaded.
        // A trailing slash is added automatically. Must equal (or sit inside)
        // the key's namePrefix when enforce_key_scope is on.
        'prefix'          => 'camera-footage/',
        'enforce_key_scope' => true,
        'files_per_page'  => 100,  // 1–1000
    ],

    // -----------------------------------------------------------------
    // Authentication (REQUIRED)
    // -----------------------------------------------------------------
    // Generate the hash with:   php bin/hash-password.php
    // Generate a TOTP secret:   php bin/totp-setup.php <username>
    'auth' => [
        'username'      => '',     // REQUIRED
        'password_hash' => '',     // REQUIRED — a password_hash() string, never plaintext
        'totp_secret'   => '',     // Base32; leave empty to disable MFA (not recommended)
        'totp_issuer'   => 'B2 File Browser',
        // Show the TOTP secret + QR code to logged-in users at ?mfa_setup.
        // Off by default: anyone holding a session could copy your second
        // factor. Prefer bin/totp-setup.php on the server instead.
        'mfa_setup_page' => false,
        // Refuse to serve anything unless credentials are configured. Only
        // set true if something else (e.g. a reverse proxy) authenticates.
        'allow_no_auth' => false,
    ],

    // -----------------------------------------------------------------
    // Sessions
    // -----------------------------------------------------------------
    'session' => [
        'idle_timeout'     => 1800,   // seconds of inactivity before logout
        'absolute_timeout' => 43200,  // hard cap on session age (12 h)
        'cookie_name'      => 'b2b_session',
        // Mark the cookie Secure. Leave true unless you are testing over
        // plain HTTP on localhost.
        'secure_cookie'    => true,
    ],

    // -----------------------------------------------------------------
    // Brute-force protection
    // -----------------------------------------------------------------
    'rate_limit' => [
        'max_attempts'     => 5,     // failures (password or TOTP) before lockout
        'window'           => 300,   // seconds in which failures are counted
        'lockout_duration' => 900,   // seconds an address stays locked
        'ipv6_prefix'      => 64,    // IPv6 clients are grouped by this prefix length
    ],

    // -----------------------------------------------------------------
    // Writable storage (REQUIRED to exist and be writable by PHP)
    // -----------------------------------------------------------------
    // Holds the rate-limit table, TOTP replay table and B2 token cache.
    // Keep it outside the web root and readable only by the PHP user.
    'storage_dir' => '/home/username/b2-browser-data',

    // -----------------------------------------------------------------
    // Media handling
    // -----------------------------------------------------------------
    'media' => [
        'video_extensions'  => ['mp4', 'm4v', 'mov', 'webm'],
        'image_extensions'  => ['jpg', 'jpeg', 'png', 'webp'],
        'signed_url_ttl'    => 900,   // seconds a player link stays valid
        'thumbnails'        => true,  // inline previews for images in a folder
        'thumbnail_ttl'     => 600,   // seconds a folder's thumbnail token stays valid
        // Path of a day's folder relative to the prefix, used by the
        // "jump to date" picker. Tokens: {Y} {m} {d}. Empty disables it.
        'date_path_format'  => '{Y}/{m}/{d}/',
    ],

    // -----------------------------------------------------------------
    // Site
    // -----------------------------------------------------------------
    'site' => [
        'title'    => 'Camera Backup Browser',
        'homepage' => 'https://yourdomain.com',  // where "Return to homepage" / logout go
        'hsts'     => true,   // Strict-Transport-Security (only sent over HTTPS)
    ],

    // -----------------------------------------------------------------
    // Look and feel — match the site b2-browser lives on (all optional)
    // -----------------------------------------------------------------
    // Asset paths must be on this site (the CSP only allows 'self');
    // navigation links may also be absolute http(s) URLs.
    'theme' => [
        // Loaded before the app's own stylesheet. If your site's CSS defines
        // --bg, --text, --text-muted, --border, --accent and --accent-hover,
        // the app picks those colours up automatically. Put fonts and width
        // in your own theme.css (copy public/assets/theme.example.css).
        'stylesheets'  => [],          // e.g. ['/style.css', 'assets/theme.css']
        'logo'         => '',          // e.g. '/images/logo.svg' (shown at 64×64)
        'logo_alt'     => '',
        'heading'      => '',          // text beside the logo, e.g. 'example.org'
        'heading_link' => '',          // defaults to site.homepage
        'nav'          => [],          // e.g. [['HOME', '/'], ['ABOUT', '/about.php']]
        'favicon'      => '',          // e.g. '/favicon.ico'
        // Image shown above the title on the sign-in page instead of the
        // padlock (height capped at 56 px; override with --b2-login-logo-height).
        'login_logo'     => '',        // e.g. 'assets/backblaze.svg'
        'login_logo_alt' => '',        // e.g. 'Backblaze'
        'toggle'       => true,        // light/dark switch in the navigation bar
        // localStorage key for the light/dark choice. Use the same key as
        // your site's own toggle so the two stay in step.
        'storage_key'  => 'b2-theme',
        // Reproduce your site's own header/menu markup so its stylesheet lays
        // them out exactly as on your other pages. Give the CSS class names
        // your site uses; wrapper, header and nav are all needed to enable it.
        // The header becomes <div class=header><img logo><h1>heading</h1></div>
        // and the nav <div class=nav><a>…</a>… <button class=toggle></div>.
        'classes' => [
            'wrapper' => '',   // e.g. 'blended_grid'
            'header'  => '',   // e.g. 'pageHeader'
            'nav'     => '',   // e.g. 'pageLeftMenu'
            'toggle'  => '',   // e.g. 'theme-toggle'
        ],
    ],

    // -----------------------------------------------------------------
    // Activity log
    // -----------------------------------------------------------------
    'log' => [
        'enabled'   => true,
        'path'      => '/home/username/logs/b2_activity.log',
        'max_bytes' => 10485760,  // rotate at 10 MB
    ],

    // -----------------------------------------------------------------
    // Alerts (email and/or ntfy)
    // -----------------------------------------------------------------
    'alerts' => [
        'on_login'        => true,
        'on_failed_login' => true,   // once, when an address reaches 3 failures
        'on_lockout'      => true,   // once, when the lock is applied
        'on_download'     => false,
        'on_playback'     => false,
        'email' => [
            'enabled'    => false,
            'from'       => 'alerts@yourdomain.com',
            'reply_to'   => 'admin@yourdomain.com',
            'recipients' => ['admin@yourdomain.com'],
        ],
        'ntfy' => [
            'enabled' => false,
            'server'  => 'https://ntfy.sh',   // your own instance if self-hosted
            'topic'   => '',
            'token'   => '',                   // access token if the topic is protected
        ],
    ],

    // Show detailed error messages in the browser. Never enable in production.
    'debug' => false,
];
